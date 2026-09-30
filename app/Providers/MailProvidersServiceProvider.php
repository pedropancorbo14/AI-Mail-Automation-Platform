<?php

namespace App\Providers;

use App\Mail\MailProviderFactory;
use App\Mail\Security\MailHostGuard;
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

        $this->app->bind(MailHostGuard::class, function () {
            return new MailHostGuard(config('mail_connections.allow_private_hosts'));
        });
    }
}
