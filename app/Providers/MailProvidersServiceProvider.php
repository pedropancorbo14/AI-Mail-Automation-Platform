<?php

namespace App\Providers;

use App\Mail\Data\MailAddress;
use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Mail\OAuth\OAuthClients;
use App\Mail\OAuth\OAuthTokenManager;
use App\Mail\Providers\Imap\ImapClient;
use App\Mail\Providers\Imap\ImapSmtpProvider;
use App\Mail\Providers\Imap\SmtpClient;
use App\Mail\Providers\Microsoft\GraphClient;
use App\Mail\Providers\Microsoft\MicrosoftGraphProvider;
use App\Mail\Providers\Microsoft\MicrosoftOAuthClient;
use App\Mail\Security\MailHostGuard;
use App\Models\MailConnection;
use Illuminate\Support\ServiceProvider;

/**
 * Registra la factory de proveedores de correo y cada implementación
 * (IMAP/SMTP, Microsoft Graph, Gmail) según se van añadiendo.
 */
class MailProvidersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailProviderFactory::class, function () {
            return new MailProviderFactory();
        });

        $this->app->singleton(OAuthClients::class, function () {
            $clients = new OAuthClients();

            $clients->register(new MicrosoftOAuthClient(
                config('services.microsoft.client_id'),
                config('services.microsoft.client_secret'),
                config('services.microsoft.tenant'),
                config('services.microsoft.redirect_uri'),
                config('mail_connections.timeout'),
            ));

            return $clients;
        });

        $this->app->bind(MailHostGuard::class, function () {
            return new MailHostGuard(config('mail_connections.allow_private_hosts'));
        });
    }

    public function boot(): void
    {
        $factory = $this->app->make(MailProviderFactory::class);

        $factory->extend(MailProviderType::Imap, function (MailConnection $connection) {
            $guard = $this->app->make(MailHostGuard::class);
            $timeout = config('mail_connections.timeout');

            return new ImapSmtpProvider(
                new ImapClient($connection->credentials, $guard, $timeout),
                new SmtpClient($connection->credentials, $guard, $timeout),
                new MailAddress($connection->email, $connection->name),
                config('mail_connections.max_attachment_mb') * 1024 * 1024,
            );
        });

        // El token se renueva solo al caducar y se guarda en la conexión.
        $factory->extend(MailProviderType::Microsoft, fn (MailConnection $connection) => new MicrosoftGraphProvider(
            new GraphClient(
                new OAuthTokenManager($connection, $this->app->make(OAuthClients::class)->for(MailProviderType::Microsoft)),
                config('mail_connections.timeout'),
            ),
            $connection->credentials->scopes,
        ));
    }
}
