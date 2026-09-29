<?php

use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Laravel 11+ ya no incluye throttle en el grupo api por defecto;
        // aplica el RateLimiter 'api' definido en AppServiceProvider.
        $middleware->throttleApi();

        $middleware->alias(['tenant' => ResolveTenant::class]);

        // El tenant debe estar fijado antes del route model binding: los
        // modelos con BelongsToTenant no se pueden consultar sin tenant.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );

        // En la API no se redirige a 'login' (no existe): sin redirección,
        // la AuthenticationException se convierte en 401 JSON más abajo.
        $middleware->redirectGuestsTo(
            fn(Request $request) => $request->is('api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Los datos proporcionados no son válidos.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });

        // Laravel ya ha convertido aquí ModelNotFoundException y
        // AuthorizationException (incluido denyAsNotFound) en excepciones HTTP.
        // Mensajes fijos: no exponen nombres de modelos ni detalles internos.
        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match ($e->getStatusCode()) {
                403 => response()->json(['message' => 'No autorizado.'], 403, $e->getHeaders()),
                404 => response()->json(['message' => 'Recurso no encontrado.'], 404, $e->getHeaders()),
                default => null,
            };
        });

        // Solo errores inesperados: las excepciones HTTP (403, 405, 429...)
        // conservan su código aunque APP_DEBUG=false.
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*') && ! app()->hasDebugModeEnabled() && ! $e instanceof HttpExceptionInterface) {
                return response()->json(['message' => 'Error interno del servidor.'], 500);
            }
        });
    })->create();
