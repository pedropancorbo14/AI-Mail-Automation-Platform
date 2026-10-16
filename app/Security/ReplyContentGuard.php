<?php

namespace App\Security;

use App\Mail\Support\HtmlToText;
use App\Security\Audit\AuditLogger;
use App\Models\AiRequest;
use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\EmailReply;
use App\Models\MailConnection;
use App\Tools\Contracts\AITool;
use App\Tools\Enums\ToolCallStatus;
use App\Tools\ToolRegistry;
use Illuminate\Support\Str;

/**
 * Revisa el TEXTO de una respuesta antes de enviarla sin persona (lo usa
 * AutoSendPolicy). Comprobaciones deterministas, sin coste de IA, contra lo
 * que la IA sí tenía delante:
 *
 *  - "Datos de la empresa": lo que devolvieron sus herramientas en esta
 *    ejecución (pedidos, fragmentos de documentación) + los dominios de sus
 *    buzones.
 *  - "Pruebas": lo anterior + el email del cliente.
 *
 * Cualquier problema → la respuesta la revisa una persona (nunca se
 * reescribe). Dentro del tenant.
 */
class ReplyContentGuard
{
    /** Promete una revisión humana: falso si la respuesta sale sola. */
    private const array HUMAN_PROMISES = [
        '/\b(una|otra) persona (de nuestro equipo )?(revisar|se pondr|te contactar|te escribir|te responder)/iu',
        '/\b(un|una) (compañer[oa]|agente|miembro del equipo|especialista|responsable) (revisar|se pondr|te contactar|te escribir|te responder)/iu',
        '/\b(revisaremos|estudiaremos) (tu|su) caso\b/iu',
        '/\b(someone|a (member of our|colleague|team member|specialist|person))\b[^.]{0,40}\b(will|shall) (review|contact|get back|reach out)/iu',
    ];

    /** Restos del prompt o de la maquinaria interna que el cliente no debe ver. */
    private const array INTERNAL_MARKERS = [
        '<email>', '</email>', '[email]', '[/email]', '<documentacion', '</documentacion', '<fuente', '[fuente',
        'system prompt', 'prompt del sistema', 'instrucciones del sistema', AuditLogger::HIDDEN,
    ];

    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    /** @return list<string> Problemas encontrados (vacío = el texto puede salir solo). */
    public function problems(Email $email, EmailReply $reply): array
    {
        $body = (string) $reply->body;
        $companyData = $this->toolResults($reply);
        $evidence = $companyData . "\n" . $email->subject . "\n" . ($email->text_body ?: HtmlToText::convert((string) $email->html_body));
        $problems = [];

        if (trim($body) === '') {
            return ['La respuesta está vacía.'];
        }

        if (mb_strlen($body) > ($max = (int) config('ai.automation.max_auto_reply_chars'))) {
            $problems[] = "La respuesta es demasiado larga para enviarla sola (más de {$max} caracteres).";
        }

        if (collect(self::HUMAN_PROMISES)->contains(fn (string $pattern) => preg_match($pattern, $body))) {
            $problems[] = 'La respuesta dice que una persona revisará el caso: no puede enviarse sola.';
        }

        if ($marker = $this->internalMarker($body)) {
            $problems[] = "La respuesta contiene detalles internos de la plataforma («{$marker}»).";
        }

        if ($hosts = $this->unknownLinks($body, $companyData)) {
            $problems[] = 'La respuesta incluye enlaces que no son de la empresa: ' . implode(', ', $hosts) . '.';
        }

        if ($addresses = $this->unknownAddresses($email, $body, $companyData)) {
            $problems[] = 'La respuesta menciona direcciones de email que no son del cliente ni de la empresa: ' . implode(', ', $addresses) . '.';
        }

        if ($orders = $this->unknownOrders($body, $evidence)) {
            $problems[] = 'La respuesta menciona pedidos que no aparecen en el email ni en los datos consultados: ' . implode(', ', $orders) . '.';
        }

        if ($amounts = $this->unknownAmounts($body, $evidence)) {
            $problems[] = 'La respuesta da importes que no aparecen en el email ni en los datos consultados: ' . implode(', ', $amounts) . '.';
        }

        return $problems;
    }

    /** Lo que devolvieron con éxito las herramientas de la ejecución que propuso la respuesta. */
    private function toolResults(EmailReply $reply): string
    {
        if ($reply->agent_run_id === null) {
            return '';
        }

        return AiToolCall::query()
            ->whereIn('ai_request_id', AiRequest::where('agent_run_id', $reply->agent_run_id)->select('id'))
            ->where('status', ToolCallStatus::Success)
            ->pluck('result')
            ->map(fn ($result) => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode("\n");
    }

    private function internalMarker(string $body): ?string
    {
        $markers = [...self::INTERNAL_MARKERS, ...array_map(fn (AITool $tool) => $tool->name(), $this->tools->all())];

        foreach ($markers as $marker) {
            if (Str::contains($body, $marker, ignoreCase: true)) {
                return $marker;
            }
        }

        return null;
    }

    /**
     * Enlaces cuyo dominio no es de un buzón de la empresa ni sale de sus
     * datos. Los del email del cliente NO valen: una inyección podría
     * conseguir que la empresa reenvíe un enlace de phishing con su nombre.
     *
     * @return list<string>
     */
    private function unknownLinks(string $body, string $companyData): array
    {
        preg_match_all('~\b(?:https?://|www\.)([a-z0-9.-]+\.[a-z]{2,})~iu', $body, $matches);
        $allowed = [...$this->companyDomains(), ...$this->hostsIn($companyData)];

        return collect($matches[1])
            ->map(fn (string $host) => Str::lower(preg_replace('/^www\./i', '', $host)))
            ->unique()
            ->reject(fn (string $host) => collect($allowed)->contains(fn (string $domain) => $host === $domain || str_ends_with($host, ".{$domain}")))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function unknownAddresses(Email $email, string $body, string $companyData): array
    {
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/iu', $body, $matches);
        $known = Str::lower($companyData . ' ' . $email->from_email . ' ' . $email->recipients()->pluck('email')->implode(' '));
        $domains = $this->companyDomains();

        return collect($matches[0])
            ->map(fn (string $address) => Str::lower($address))
            ->unique()
            ->reject(fn (string $address) => str_contains($known, $address) || in_array(Str::after($address, '@'), $domains, true))
            ->values()
            ->all();
    }

    /** @return list<string> Números de pedido citados que no están en las pruebas. */
    private function unknownOrders(string $body, string $evidence): array
    {
        preg_match_all('/(?:\bpedido|\border)\s*(?:n(?:º|°|o\.?|úm(?:ero)?\.?)\s*|number\s*)?#?\s*(\d{3,})|#(\d{3,})/iu', $body, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(fn (array $match) => ($match[2] ?? '') !== '' ? $match[2] : $match[1])
            ->unique()
            ->reject(fn (string $number) => preg_match('/(?<!\d)' . $number . '(?!\d)/', $evidence))
            ->map(fn (string $number) => "#{$number}")
            ->values()
            ->all();
    }

    /** @return list<string> Importes con moneda que no salen de las pruebas. */
    private function unknownAmounts(string $body, string $evidence): array
    {
        // Con separadores de miles ("1.234,50", "2 500") o sin ellos ("1234.5", "14,95").
        $number = '\d{1,3}(?:[.,\s]\d{3})+(?:[.,]\d{1,2})?(?!\d)|\d+(?:[.,]\d{1,2})?(?!\d)';
        preg_match_all("/(?:({$number})\s?(?:€|eur(?:os?)?\b|\\$|usd\b|dólares))|(?:(?:€|\\$)\s?({$number}))/iu", $body, $matches, PREG_SET_ORDER);

        preg_match_all("/{$number}/u", $evidence, $all);
        $known = array_flip(array_map(self::canonicalAmount(...), $all[0]));

        return collect($matches)
            ->map(fn (array $match) => trim(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')))
            ->filter()
            ->reject(fn (string $amount) => isset($known[self::canonicalAmount($amount)]))
            ->unique()
            ->values()
            ->all();
    }

    /** "1.234,50", "1,234.5", "1234.50" y "1234,5" → "1234.50". */
    public static function canonicalAmount(string $value): string
    {
        $value = preg_replace('/\s/u', '', $value);

        if (preg_match('/^(.*)[.,](\d{1,2})$/', $value, $m)) {
            return sprintf('%d.%02d', (int) preg_replace('/\D/', '', $m[1]), (int) str_pad($m[2], 2, '0'));
        }

        return sprintf('%d.00', (int) preg_replace('/\D/', '', $value));
    }

    /** @return list<string> Dominios de los buzones de la empresa. */
    private function companyDomains(): array
    {
        return MailConnection::query()->pluck('email')->map(fn (string $address) => Str::lower(Str::after($address, '@')))->unique()->values()->all();
    }

    /** @return list<string> */
    private function hostsIn(string $text): array
    {
        preg_match_all('~(?:https?://|www\.)([a-z0-9.-]+\.[a-z]{2,})~iu', $text, $matches);

        return array_values(array_unique(array_map(fn (string $host) => Str::lower(preg_replace('/^www\./i', '', $host)), $matches[1])));
    }
}
