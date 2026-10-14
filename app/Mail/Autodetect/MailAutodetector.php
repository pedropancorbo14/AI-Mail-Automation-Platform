<?php

namespace App\Mail\Autodetect;

use App\Mail\Enums\MailEncryption;
use App\Mail\Security\MailHostGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

/**
 * Propone la configuración IMAP/SMTP de una dirección de email, como hace
 * Thunderbird, en este orden:
 *   1. Base de datos pública de Thunderbird (ISPDB).
 *   2. Autoconfig del propio dominio (autoconfig.dominio y .well-known).
 *   3. Registros DNS SRV (RFC 6186 / 8314).
 *   4. Nombres habituales (imap./smtp./mail.dominio), solo si existen.
 * Además mira los MX: si el correo está en Microsoft 365 o Google, lo dice
 * (con ellos lo recomendable es conectar con OAuth).
 *
 * Es una propuesta: hay que probarla (testConnection) antes de usarla.
 * Anti-SSRF: el dominio lo escribe el usuario, así que cada URL se pide
 * solo por HTTPS, a IPs comprobadas por MailHostGuard y fijadas en la
 * conexión (CURLOPT_RESOLVE: sin DNS rebinding), sin redirecciones, con
 * tiempo y tamaño máximos.
 */
class MailAutodetector
{
    private const string ISPDB = 'https://autoconfig.thunderbird.net/v1.1/';

    private const int MAX_BYTES = 64 * 1024;

    public function __construct(
        private readonly MailHostGuard $guard,
        private readonly DnsLookup $dns,
    ) {
    }

    public function detect(string $email): AutodetectResult
    {
        $email = strtolower(trim($email));
        $domain = substr(strrchr($email, '@') ?: '', 1);

        if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($domain, '.')) {
            return new AutodetectResult(null, null, null, null);
        }

        $hint = $this->providerHint($domain);

        foreach ($this->configUrls($email, $domain) as $source => $url) {
            if ($found = $this->fromConfigUrl($url, $email, $source)) {
                return $found->withHint($hint);
            }
        }

        return ($this->fromSrv($domain, $email) ?? $this->fromGuess($domain, $email) ?? new AutodetectResult(null, null, null, null))
            ->withHint($hint);
    }

    /** @return array<string, string> fuente => URL */
    private function configUrls(string $email, string $domain): array
    {
        return [
            'ispdb' => self::ISPDB . rawurlencode($domain),
            'domain' => "https://autoconfig.{$domain}/mail/config-v1.1.xml?emailaddress=" . rawurlencode($email),
            'domain_well_known' => "https://{$domain}/.well-known/autoconfig/mail/config-v1.1.xml",
        ];
    }

    private function fromConfigUrl(string $url, string $email, string $source): ?AutodetectResult
    {
        $xml = $this->fetch($url);

        if ($xml === null) {
            return null;
        }

        $config = @simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA);

        if (! $config instanceof SimpleXMLElement || ! isset($config->emailProvider)) {
            return null;
        }

        $imap = $this->server($config->emailProvider->xpath('incomingServer[@type="imap"]') ?: [], $email);
        $smtp = $this->server($config->emailProvider->xpath('outgoingServer[@type="smtp"]') ?: [], $email);

        return $imap && $smtp ? new AutodetectResult(str_starts_with($source, 'domain') ? 'domain' : $source, null, $imap, $smtp) : null;
    }

    /**
     * Primer servidor con contraseña normal, prefiriendo SSL > STARTTLS (nunca sin cifrar).
     *
     * @param  list<SimpleXMLElement>  $servers
     * @return array{host: string, port: int, encryption: string, username: string}|null
     */
    private function server(array $servers, string $email): ?array
    {
        $candidates = [];

        foreach ($servers as $server) {
            $encryption = match (strtoupper((string) $server->socketType)) {
                'SSL' => MailEncryption::Ssl,
                'STARTTLS' => MailEncryption::StartTls,
                default => null,
            };
            // Un servidor puede admitir varios métodos (p. ej. OAuth2 y contraseña): vale si alguno es con contraseña.
            $auths = array_map(fn ($auth) => strtolower(trim((string) $auth)), iterator_to_array($server->authentication, false)) ?: ['password-cleartext'];

            if ($encryption === null || array_intersect($auths, ['password-cleartext', 'plain', 'password-encrypted']) === []) {
                continue;
            }

            $candidates[] = [
                'host' => strtolower(trim((string) $server->hostname)),
                'port' => (int) $server->port,
                'encryption' => $encryption->value,
                'username' => $this->username((string) $server->username, $email),
            ];
        }

        usort($candidates, fn (array $a, array $b) => ($a['encryption'] === 'ssl' ? 0 : 1) <=> ($b['encryption'] === 'ssl' ? 0 : 1));

        foreach ($candidates as $candidate) {
            if ($candidate['host'] !== '' && $candidate['port'] > 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function username(string $template, string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return strtr($template ?: '%EMAILADDRESS%', [
            '%EMAILADDRESS%' => $email,
            '%EMAILLOCALPART%' => $local,
            '%EMAILDOMAIN%' => $domain,
        ]);
    }

    private function fromSrv(string $domain, string $email): ?AutodetectResult
    {
        $imap = ($srv = $this->dns->srv("_imaps._tcp.{$domain}")) ? [...$srv, 'encryption' => 'ssl']
            : (($srv = $this->dns->srv("_imap._tcp.{$domain}")) ? [...$srv, 'encryption' => 'starttls'] : null);
        $smtp = ($srv = $this->dns->srv("_submissions._tcp.{$domain}")) ? [...$srv, 'encryption' => 'ssl']
            : (($srv = $this->dns->srv("_submission._tcp.{$domain}")) ? [...$srv, 'encryption' => 'starttls'] : null);

        if (! $imap || ! $smtp) {
            return null;
        }

        return new AutodetectResult('srv', null, [...$imap, 'username' => $email], [...$smtp, 'username' => $email]);
    }

    /** imap./smtp./mail.dominio: solo si existen y no son internos. */
    private function fromGuess(string $domain, string $email): ?AutodetectResult
    {
        $first = fn (array $hosts) => collect($hosts)->first(fn (string $host) => $this->guard->allowedIps($host) !== []);
        $imap = $first(["imap.{$domain}", "mail.{$domain}"]);
        $smtp = $first(["smtp.{$domain}", "mail.{$domain}"]);

        if (! $imap || ! $smtp) {
            return null;
        }

        return new AutodetectResult(
            'guess',
            null,
            ['host' => $imap, 'port' => 993, 'encryption' => 'ssl', 'username' => $email],
            ['host' => $smtp, 'port' => 465, 'encryption' => 'ssl', 'username' => $email],
        );
    }

    /** Microsoft 365 o Google Workspace/Gmail según los MX del dominio. */
    private function providerHint(string $domain): ?string
    {
        foreach ($this->dns->mx($domain) as $mx) {
            if (str_ends_with($mx, '.mail.protection.outlook.com') || str_ends_with($mx, '.olc.protection.outlook.com')) {
                return 'microsoft';
            }

            if (str_ends_with($mx, '.google.com') || str_ends_with($mx, '.googlemail.com') || $mx === 'smtp.google.com') {
                return 'google';
            }
        }

        return null;
    }

    /** GET por HTTPS a una IP comprobada, sin redirecciones y con tamaño máximo. */
    private function fetch(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = $this->guard->allowedIps($host);

        if ($ips === []) {
            return null;
        }

        try {
            $response = Http::timeout(4)
                ->connectTimeout(3)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ips[0]}"]],
                ])
                ->accept('application/xml, text/xml')
                ->get($url);
        } catch (ConnectionException $e) {
            Log::debug('Autodetección: sin respuesta', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        $body = $response->body();

        return $response->ok() && strlen($body) <= self::MAX_BYTES ? $body : null;
    }
}
