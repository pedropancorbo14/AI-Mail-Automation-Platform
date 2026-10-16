<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use App\Tenancy\TenantService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una instancia por petición HTTP / job de cola (ver TenantService).
        $this->app->scoped(TenantService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Anti fuerza bruta: 5 intentos/min por combinación email + IP.
        RateLimiter::for('login', function ($request) {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')) . '|' . $request->ip());
        });

        // Probar una conexión abre conexiones a servidores externos: límite
        // bajo para que no se pueda usar como escáner de puertos.
        RateLimiter::for('mail-connection-test', function ($request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Cada permiso del catálogo es una Gate: $user->can('users.create'),
        // middleware can:users.create, @can en vistas. Las policies lo usan
        // además de comprobar que el recurso es del mismo tenant.
        foreach (array_keys(Permission::CATALOG) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Política de contraseñas común (alta, edición y cambio de la propia).
        // En producción también se rechazan las que aparecen en filtraciones
        // (Have I Been Pwned por k-anonimato: solo salen 5 caracteres del SHA-1).
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(10)->max(128)->uncompromised()
            : Password::min(10)->max(128));
    }
}
