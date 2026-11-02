<?php

use App\Billing\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\Signup\AccountExistsMessage;
use App\Tenancy\Signup\VerifyEmailMessage;
use Illuminate\Support\Facades\Mail;

/*
 * Alta de empresas desde la web (Fase 17, tarea 6): empresa en prueba +
 * propietario que no entra hasta confirmar el email, sin revelar qué emails
 * tienen cuenta.
 */

beforeEach(function () {
    Mail::fake();
    config(['plans.signup_enabled' => true, 'app.url' => 'http://localhost']);
});

function signupData(array $overrides = []): array
{
    return [
        'company' => 'Ferretería López',
        'name' => 'Lucía López',
        'email' => 'Lucia@Ferreteria.Example',
        'password' => 'una-contraseña-larga',
        'password_confirmation' => 'una-contraseña-larga',
        'accept_terms' => true,
        ...$overrides,
    ];
}

function verificationUrl(): string
{
    $url = null;
    Mail::assertSent(VerifyEmailMessage::class, function (VerifyEmailMessage $mail) use (&$url) {
        $url = $mail->url;

        return true;
    });

    return $url;
}

it('creates a company on trial with an unverified owner and sends the link', function () {
    $this->postJson('/api/v1/signup', signupData())
        ->assertStatus(202)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'te hemos enviado un email'));

    $tenant = Tenant::where('slug', 'ferreteria-lopez')->sole();
    $owner = User::where('email', 'lucia@ferreteria.example')->sole();

    expect($tenant->plan)->toBe('trial')
        ->and($tenant->subscription_status)->toBe(SubscriptionStatus::Trialing)
        ->and((int) round(now()->diffInDays($tenant->trial_ends_at)))->toBe(14)
        ->and($owner->tenant_id)->toBe($tenant->id)
        ->and($owner->hasRole(Role::OWNER))->toBeTrue()
        ->and($owner->email_verified_at)->toBeNull();

    Mail::assertSent(VerifyEmailMessage::class, fn ($mail) => $mail->hasTo('lucia@ferreteria.example'));
    expect(AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'tenant.signed_up')->sole()->tenant_id)->toBe($tenant->id);
});

it('does not let the owner in until the email is confirmed', function () {
    $this->postJson('/api/v1/signup', signupData());
    $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'lucia@ferreteria.example', 'password' => 'una-contraseña-larga']);

    $login()->assertForbidden()->assertJsonPath('code', 'email_unverified');
    // Con la contraseña mala no se dice nada del email.
    $this->postJson('/api/v1/auth/login', ['email' => 'lucia@ferreteria.example', 'password' => 'otra-cosa-cualquiera'])
        ->assertUnprocessable();

    $this->get(verificationUrl())->assertRedirect('http://localhost/login?verified=1');

    $login()->assertOk();
    expect(AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'auth.email_verified')->exists())->toBeTrue();
});

it('rejects tampered or expired links', function () {
    $this->postJson('/api/v1/signup', signupData());
    $url = verificationUrl();

    $this->get($url.'x')->assertRedirect('http://localhost/login?verified=invalid');
    $this->get(preg_replace('#/([0-9a-f]{40})\?#', '/'.sha1('otro@x.example').'?', $url))->assertRedirect('http://localhost/login?verified=invalid');

    $this->travel(25)->hours();
    $this->get($url)->assertRedirect('http://localhost/login?verified=invalid');

    expect(User::where('email', 'lucia@ferreteria.example')->value('email_verified_at'))->toBeNull();
});

it('answers the same for an email that already has an account, and warns its owner', function () {
    $existing = User::factory()->create(['email' => 'lucia@ferreteria.example']);
    $companies = Tenant::count();

    $this->postJson('/api/v1/signup', signupData())->assertStatus(202);

    expect(Tenant::count())->toBe($companies);
    Mail::assertSent(AccountExistsMessage::class, fn ($mail) => $mail->hasTo($existing->email));
    Mail::assertNotSent(VerifyEmailMessage::class);
});

it('validates the form and ignores robots', function () {
    $this->postJson('/api/v1/signup', signupData(['password' => 'corta', 'password_confirmation' => 'corta', 'accept_terms' => false, 'email' => 'no-es-un-email']))
        ->assertJsonValidationErrors(['password', 'accept_terms', 'email']);

    $this->postJson('/api/v1/signup', signupData(['website' => 'http://spam.example']))->assertStatus(202);
    expect(User::where('email', 'lucia@ferreteria.example')->exists())->toBeFalse();
});

it('gives each company its own identifier', function () {
    Tenant::factory()->create(['slug' => 'ferreteria-lopez']);

    $this->postJson('/api/v1/signup', signupData());

    expect(User::where('email', 'lucia@ferreteria.example')->sole()->tenant->slug)->toStartWith('ferreteria-lopez-');
});

it('resends the link only to unverified accounts, without saying which', function () {
    User::factory()->create(['email' => 'ya@confirmado.example']);
    $this->postJson('/api/v1/signup', signupData());

    $this->postJson('/api/v1/auth/verify-email/resend', ['email' => 'ya@confirmado.example'])->assertStatus(202);
    $this->postJson('/api/v1/auth/verify-email/resend', ['email' => 'nadie@nada.example'])->assertStatus(202);
    $this->postJson('/api/v1/auth/verify-email/resend', ['email' => 'lucia@ferreteria.example'])->assertStatus(202);

    Mail::assertSent(VerifyEmailMessage::class, 2);
});

it('limits sign ups per address', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/signup', signupData(['company' => "Empresa {$i}"]))->assertStatus(202);
    }

    $this->postJson('/api/v1/signup', signupData(['company' => 'Otra']))->assertTooManyRequests();
});

it('does not exist when sign up is disabled', function () {
    config(['plans.signup_enabled' => false]);

    $this->getJson('/api/v1/signup')->assertOk()->assertJsonPath('data.enabled', false);
    $this->postJson('/api/v1/signup', signupData())->assertNotFound();
    $this->postJson('/api/v1/auth/verify-email/resend', ['email' => 'x@y.example'])->assertNotFound();
});

it('marks users created by a company or by the operator as verified', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->for($tenant)->owner()->create();

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/users', ['name' => 'Bea', 'email' => 'bea@acme.example', 'password' => 'una-contraseña-larga', 'role' => 'agent'])
        ->assertCreated();
    $this->artisan('tenant:create', ['name' => 'Globex', 'email' => 'ana@globex.example', '--generate-password' => true])->assertSuccessful();

    expect(User::where('email', 'bea@acme.example')->value('email_verified_at'))->not->toBeNull()
        ->and(User::where('email', 'ana@globex.example')->value('email_verified_at'))->not->toBeNull();
});
