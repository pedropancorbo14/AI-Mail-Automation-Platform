<?php

use App\Models\User;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/*
 * Cabeceras de seguridad en el panel, la API y las métricas.
 */

/** Vite de pruebas: no pinta nada y "está en marcha" solo si se da su URL (sin tocar public/hot). */
function viteRunningAt(?string $url): void
{
    $hot = storage_path('framework/testing-vite-hot');
    $url === null ? @unlink($hot) : file_put_contents($hot, $url);

    test()->swap(Vite::class, new class($hot) extends Vite
    {
        public function __construct(private readonly string $hot)
        {
        }

        public function __invoke($entrypoints, $buildDirectory = null)
        {
            return new HtmlString('');
        }

        public function hotFile()
        {
            return $this->hot;
        }
    });
}

it('sends the security headers on every response', function (string $uri) {
    viteRunningAt(null);

    $response = $this->get($uri);

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('X-Powered-By');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('geolocation=()');
})->with(['/', '/emails/3', '/api/v1/health', '/api/v1/no-existe', '/metrics']);

it('gives the api a policy that loads nothing', function () {
    $this->getJson('/api/v1/health')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

    $user = User::factory()->owner()->create();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
});

it('only lets the panel load resources from its own origin', function () {
    viteRunningAt(null);

    $policy = $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

    expect($policy)->toContain("default-src 'self'")
        ->toContain("script-src 'self';")
        ->toContain("connect-src 'self';")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'none'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('unsafe-eval')
        ->not->toContain('localhost:5173');
});

it('allows the vite dev server only while it is running', function () {
    viteRunningAt('http://localhost:5173');

    $policy = $this->get('/')->headers->get('Content-Security-Policy');
    viteRunningAt(null);

    expect($policy)->toContain("script-src 'self' http://localhost:5173 ws://localhost:5173")
        ->toContain("connect-src 'self' http://localhost:5173 ws://localhost:5173");
});

it('adds hsts only over https', function () {
    $this->getJson('/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');

    $this->getJson('https://localhost/api/v1/health')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
