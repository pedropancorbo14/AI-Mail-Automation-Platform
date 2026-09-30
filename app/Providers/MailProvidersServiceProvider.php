<?php

namespace App\Providers;

use App\Mail\MailProviderFactory;
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
    }
}
