<?php

namespace App\Providers;

use App\Billing\Observers\EmailUsageObserver;
use App\Billing\UsageMeter;
use App\Models\Email;
use Illuminate\Support\ServiceProvider;

/** Planes, uso y facturación (Fase 17). */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UsageMeter::class);
    }

    public function boot(): void
    {
        Email::observe(EmailUsageObserver::class);
    }
}
