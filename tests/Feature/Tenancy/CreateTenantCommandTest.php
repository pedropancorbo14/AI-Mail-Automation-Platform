<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/*
 * tenant:create (Fase 16): el alta de empresas en producción, donde no hay
 * seeders de demostración ni registro público. Y quién abre /horizon.
 */

it('creates a company with its owner and a generated password', function () {
    $this->artisan('tenant:create', ['name' => 'Acme Producción', 'email' => 'Ana@Acme.Example', '--owner-name' => 'Ana', '--generate-password' => true])
        ->expectsOutputToContain('Empresa "Acme Producción" creada')
        ->expectsOutputToContain('Contraseña (no se volverá a mostrar)')
        ->assertSuccessful();

    $tenant = Tenant::where('slug', 'acme-produccion')->firstOrFail();
    $owner = User::where('email', 'ana@acme.example')->firstOrFail();

    expect($owner->tenant_id)->toBe($tenant->id)
        ->and($owner->name)->toBe('Ana')
        ->and($owner->hasRole(Role::OWNER))->toBeTrue()
        ->and($tenant->is_active)->toBeTrue();

    $log = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'tenant.created')->sole();
    expect($log->tenant_id)->toBe($tenant->id)
        ->and($log->metadata)->toEqualCanonicalizing(['owner_email' => 'ana@acme.example', 'plan' => 'trial']);
});

it('asks for the password without showing it', function () {
    $this->artisan('tenant:create', ['name' => 'Globex', 'email' => 'owner@globex.example', '--slug' => 'globex-sl'])
        ->expectsQuestion('Contraseña del propietario', 'una-contraseña-larga')
        ->doesntExpectOutputToContain('una-contraseña-larga')
        ->assertSuccessful();

    expect(Hash::check('una-contraseña-larga', User::where('email', 'owner@globex.example')->value('password')))->toBeTrue()
        ->and(Tenant::where('slug', 'globex-sl')->exists())->toBeTrue();
});

it('rejects weak passwords, repeated emails and repeated slugs without creating anything', function () {
    $this->artisan('tenant:create', ['name' => 'Corta', 'email' => 'a@corta.example'])
        ->expectsQuestion('Contraseña del propietario', 'corta')
        ->assertFailed();

    $existing = User::factory()->owner()->create(['email' => 'taken@example.com']);
    $this->artisan('tenant:create', ['name' => 'Otra', 'email' => 'TAKEN@example.com', '--generate-password' => true])->assertFailed();
    $this->artisan('tenant:create', ['name' => $existing->tenant->name, 'email' => 'nuevo@example.com', '--slug' => $existing->tenant->slug, '--generate-password' => true])->assertFailed();

    expect(Tenant::count())->toBe(1)->and(User::count())->toBe(1);
});

it('opens horizon only to the configured operators', function () {
    $operator = User::factory()->owner()->create(['email' => 'ops@platform.example']);
    $other = User::factory()->owner()->create();

    expect(Gate::forUser($operator)->allows('viewHorizon'))->toBeFalse();

    config(['horizon.allowed_emails' => ['ops@platform.example']]);

    expect(Gate::forUser($operator)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($other)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::allows('viewHorizon'))->toBeFalse();
});
