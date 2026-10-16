<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * Reglas de arquitectura que protegen decisiones de seguridad de fases
 * anteriores: si un cambio futuro se las salta, el CI falla.
 */

/** @return list<class-string<Model>> Todos los modelos de app/Models. */
function appModels(): array
{
    return collect(glob(app_path('Models/*.php')))
        ->map(fn (string $file) => 'App\\Models\\' . basename($file, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Model::class))
        ->values()
        ->all();
}

// --- Aislamiento por empresa (Fase 2) ---------------------------------------------

it('makes every model with tenant_id belong to a tenant', function () {
    // Excepciones deliberadas: User (Sanctum lo resuelve antes de conocer la
    // empresa; lo protegen las policies) y AuditLog (hay registros sin
    // empresa; para leer sí usa TenantScope).
    $exempt = [User::class, AuditLog::class];

    $missing = collect(appModels())
        ->reject(fn (string $class) => in_array($class, $exempt, true))
        ->filter(fn (string $class) => Schema::hasColumn((new $class)->getTable(), 'tenant_id'))
        ->reject(fn (string $class) => in_array(BelongsToTenant::class, class_uses_recursive($class), true))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('never lets tenant_id be mass assigned', function () {
    $assignable = collect(appModels())
        ->filter(fn (string $class) => (new $class)->isFillable('tenant_id'))
        ->values()
        ->all();

    expect($assignable)->toBe([]);
});

// --- Rutas: autenticación y empresa -------------------------------------------------

it('protects every api route with authentication and the tenant, except the public ones', function () {
    // Públicas a propósito: estado, login, y la vuelta de OAuth (empresa y
    // usuario salen del "state", nunca de la petición).
    $public = ['api/v1/health', 'api/v1/auth/login', 'api/v1/auth/session', 'api/v1/mail-connections/oauth/{provider}/callback'];

    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/'))
        ->reject(fn (RouteDefinition $route) => in_array($route->uri(), $public, true))
        ->reject(fn (RouteDefinition $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true)
            && in_array('tenant', $route->gatherMiddleware(), true))
        ->map(fn (RouteDefinition $route) => implode('|', $route->methods()) . ' ' . $route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('throttles the public routes that check credentials or call outside', function () {
    $route = fn (string $uri) => collect(Route::getRoutes()->getRoutes())->first(fn (RouteDefinition $r) => $r->uri() === $uri);

    foreach (['api/v1/auth/login', 'api/v1/auth/session', 'api/v1/mail-connections/oauth/{provider}/callback', 'api/v1/auth/password', 'api/v1/auth/logout-other-devices', 'api/v1/mail-connections/autodetect'] as $uri) {
        $throttles = collect($route($uri)->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
        expect($throttles->reject(fn ($m) => $m === 'throttle:api'))->not->toBeEmpty("{$uri} sin límite propio");
    }
});

// --- Código de app/: dependencias encapsuladas e higiene ----------------------------
// Con el tokenizer de PHP sobre app/ (el plugin arch() de Pest analiza
// también vendor/ y se queda sin memoria).

/** @return array<string, string> ruta relativa => código, de todo app/. */
function appSources(): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    $sources = [];

    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $sources[str_replace(base_path() . '/', '', $file->getPathname())] = file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

/** @return list<string> "fichero: función" de cada llamada a una de esas funciones globales. */
function callsTo(array $functions): array
{
    $found = [];

    foreach (appSources() as $path => $code) {
        $tokens = array_values(array_filter(token_get_all($code), fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

        foreach ($tokens as $i => $token) {
            if (is_array($token) && in_array($token[0], [T_EXIT, T_EVAL], true)) {
                $name = strtolower($token[1]);
            } elseif (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ($tokens[$i + 1] ?? null) === '(') {
                $previous = $tokens[$i - 1] ?? null;
                // $obj->exec(), Clase::system(), function exec(), new Exec(): no son la función global.
                if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                    continue;
                }
                $name = strtolower(ltrim($token[1], '\\'));
            } else {
                continue;
            }

            if (in_array($name, $functions, true)) {
                $found[] = "{$path}: {$name}";
            }
        }
    }

    return $found;
}

/** @return list<string> Ficheros de app/ que importan algo de $namespace, fuera de $allowed. */
function importsOutside(string $namespace, array $allowed): array
{
    return collect(appSources())
        ->filter(fn (string $code) => preg_match('/^use\s+\\\\?' . preg_quote($namespace, '/') . '\\\\/m', $code))
        ->keys()
        ->reject(fn (string $path) => collect($allowed)->contains(fn (string $prefix) => str_starts_with($path, $prefix)))
        ->values()
        ->all();
}

it('keeps the anthropic sdk inside its provider', function () {
    expect(importsOutside('Anthropic', ['app/AI/Providers/Anthropic/', 'app/Providers/AIServiceProvider.php']))->toBe([]);
});

it('keeps the mail libraries inside the mail module', function () {
    expect(importsOutside('DirectoryTree\ImapEngine', ['app/Mail/']))->toBe([])
        ->and(importsOutside('Symfony\Component\Mailer', ['app/Mail/']))->toBe([]);
});

it('does not let controllers query the database directly', function () {
    $controllers = array_filter(importsOutside('Illuminate\Support\Facades\DB', []), fn (string $path) => str_starts_with($path, 'app/Http/Controllers/'));

    expect($controllers)->toBe([]);
});

it('has no debugging leftovers', function () {
    expect(callsTo(['dd', 'dump', 'ddd', 'var_dump', 'print_r', 'var_export', 'ray', 'die', 'exit', 'phpinfo']))->toBe([]);
});

it('never executes shell commands, eval or unserialize', function () {
    expect(callsTo(['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'eval', 'unserialize', 'assert']))->toBe([]);
});

it('only reads env() in config files', function () {
    expect(callsTo(['env']))->toBe([]);
});

it('detects what it forbids (the checker itself works)', function () {
    $file = app_path('ArchitectureProbe.php');
    file_put_contents($file, "<?php\nnamespace App;\nuse Anthropic\\Client;\nfunction probe() { \$x->exec(); dump(1); \\shell_exec('ls'); return env('X'); }\n");

    try {
        expect(callsTo(['dump', 'shell_exec', 'env', 'exec']))->toBe(['app/ArchitectureProbe.php: dump', 'app/ArchitectureProbe.php: shell_exec', 'app/ArchitectureProbe.php: env'])
            ->and(importsOutside('Anthropic', ['app/AI/', 'app/Providers/']))->toBe(['app/ArchitectureProbe.php']);
    } finally {
        unlink($file);
    }
});

