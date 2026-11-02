<?php

use App\AI\Remote\Contracts\EventStream;
use App\Automation\EmailProcessor;
use App\Billing\Stripe\StripeSignature;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Signup\VerifyEmailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * El recorrido SaaS de principio a fin (Fase 17): una empresa se da de alta
 * en la web, confirma el email, prueba la plataforma, choca con un límite de
 * la prueba, contrata un plan con Stripe (simulado), lo usa y lo cancela.
 */

it('takes a company from sign up to a paid plan and back to people only', function () {
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);
    config([
        'plans.signup_enabled' => true,
        'app.url' => 'http://localhost',
        'services.stripe.secret' => 'sk_test_clave',
        'services.stripe.webhook_secret' => 'whsec_secreto',
        'plans.plans.starter.stripe_price' => 'price_starter',
    ]);
    $stripe = function (string $type, array $object, int $created) {
        $payload = json_encode(['id' => 'evt_'.$created, 'type' => $type, 'created' => $created, 'data' => ['object' => $object]]);

        return $this->call('POST', '/api/v1/webhooks/stripe', server: [
            'HTTP_STRIPE_SIGNATURE' => StripeSignature::header($payload, 'whsec_secreto'),
            'CONTENT_TYPE' => 'application/json',
        ], content: $payload)->assertOk();
    };

    // 1. Alta y confirmación del email.
    $this->postJson('/api/v1/signup', [
        'company' => 'Ferretería López', 'name' => 'Lucía', 'email' => 'lucia@ferreteria.example',
        'password' => 'una-contraseña-larga', 'password_confirmation' => 'una-contraseña-larga', 'accept_terms' => true,
    ])->assertStatus(202);
    $url = null;
    Mail::assertSent(VerifyEmailMessage::class, function ($mail) use (&$url) {
        $url = $mail->url;

        return true;
    });
    $this->get($url)->assertRedirect('http://localhost/login?verified=1');

    $token = $this->postJson('/api/v1/auth/login', ['email' => 'lucia@ferreteria.example', 'password' => 'una-contraseña-larga'])
        ->assertOk()->json('token');
    // Cada petición como una nueva (en los tests la app y el guard se reutilizan).
    $api = function () use ($token) {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    };
    $tenant = Tenant::where('slug', 'ferreteria-lopez')->sole();

    // 2. Prueba: plan y límites.
    $api()->getJson('/api/v1/billing')
        ->assertJsonPath('data.plan.key', 'trial')
        ->assertJsonPath('data.subscription.status', 'trialing')
        ->assertJsonPath('data.plans.1.purchasable', true);
    foreach (['Bea', 'Carlos'] as $name) {
        $api()->postJson('/api/v1/users', ['name' => $name, 'email' => strtolower($name).'@ferreteria.example', 'password' => 'una-contraseña-larga', 'role' => 'agent'])
            ->assertCreated();
    }
    $api()->postJson('/api/v1/users', ['name' => 'Dani', 'email' => 'dani@ferreteria.example', 'password' => 'una-contraseña-larga', 'role' => 'agent'])
        ->assertStatus(402);

    // 3. Contrata el plan Inicial: Stripe avisa del pago y de la suscripción.
    $api()->postJson('/api/v1/billing/checkout', ['plan' => 'starter'])->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/cs_1');
    $stripe('checkout.session.completed', ['customer' => 'cus_1', 'subscription' => 'sub_1', 'metadata' => ['tenant_id' => (string) $tenant->id]], time() - 30);
    $stripe('customer.subscription.created', [
        'id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'active', 'metadata' => ['tenant_id' => (string) $tenant->id],
        'items' => ['data' => [['price' => ['id' => 'price_starter'], 'current_period_end' => now()->addMonth()->timestamp]]],
    ], time() - 20);

    expect($tenant->fresh()->plan)->toBe('starter');
    $api()->getJson('/api/v1/billing')
        ->assertJsonPath('data.plan.key', 'starter')
        ->assertJsonPath('data.subscription.status', 'active')
        ->assertJsonPath('data.billing.has_subscription', true);
    $api()->postJson('/api/v1/users', ['name' => 'Dani', 'email' => 'dani@ferreteria.example', 'password' => 'una-contraseña-larga', 'role' => 'agent'])
        ->assertCreated();

    // 4. Con la suscripción activa, los emails van a la IA.
    $streams = app(EventStream::class);
    $mailbox = inTenant($tenant, fn () => MailConnection::factory()->create(['status' => MailConnectionStatus::Active]));
    $newEmail = fn () => inTenant($tenant, fn () => Email::factory()->create(['mail_connection_id' => $mailbox->id, 'from_email' => 'cliente@cliente.test']));
    $process = fn (Email $email) => inTenant($tenant->fresh(), fn () => app(EmailProcessor::class)->process($email->fresh()));

    expect($process($newEmail()))->toBe(EmailStatus::Processing)
        ->and($streams->entries['email.received.v1'] ?? [])->toHaveCount(1);

    // 5. Cancela: la IA se para, los emails siguen llegando y van a una persona.
    $stripe('customer.subscription.deleted', [
        'id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'canceled', 'metadata' => ['tenant_id' => (string) $tenant->id],
        'items' => ['data' => [['price' => ['id' => 'price_starter']]]],
    ], time() - 10);

    expect($process($newEmail()))->toBe(EmailStatus::HumanReview)
        ->and($streams->entries['email.received.v1'] ?? [])->toHaveCount(1);
    $api()->getJson('/api/v1/billing')
        ->assertJsonPath('data.subscription.ai_block_reason', 'La suscripción está cancelada: elige un plan para seguir usando la IA.')
        ->assertJsonPath('data.usage.limits.ai_emails_per_month.used', 1)
        ->assertJsonPath('data.usage.emails_received', 2);

    expect(User::where('tenant_id', $tenant->id)->count())->toBe(4);
});
