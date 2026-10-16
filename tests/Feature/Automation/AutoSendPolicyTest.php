<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\AI\Classification\EmailIntent;
use App\Automation\AutomationMode;
use App\Automation\AutoSendDecision;
use App\Automation\AutoSendPolicy;
use App\Mail\Enums\RecipientType;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->configureAutomation(AutomationMode::Auto);

    [$this->email, $this->reply] = inTenant($this->tenant, function () {
        $email = Email::factory()->create([
            'mail_connection_id' => MailConnection::factory()->create(['email' => 'soporte@empresa.test']),
            'from_email' => 'cliente@cliente.test',
            // La respuesta de draftReply() cita el pedido: tiene que salir de algún sitio (ReplyContentGuard).
            'text_body' => '¿Dónde está mi pedido 45821?',
        ]);
        $email->forceFill(['intent' => EmailIntent::OrderStatus, 'classified_at' => now()])->save();

        return [$email, draftReply($email)];
    });
});

function autoSendDecision(): AutoSendDecision
{
    return inTenant(test()->tenant, fn () => app(AutoSendPolicy::class)->check(test()->email->fresh(), test()->reply->fresh()));
}

/** Respuesta automática ya enviada a $to hace $hoursAgo horas. */
function sentAutoReply(string $to, int $hoursAgo = 1, bool $automatic = true, ?Tenant $tenant = null): void
{
    $tenant ??= test()->tenant;

    inTenant($tenant, function () use ($to, $hoursAgo, $automatic) {
        draftReply(Email::factory()->create())->forceFill([
            'status' => EmailReplyStatus::Sent,
            'sent_automatically' => $automatic,
            'sent_to' => $to,
            'sent_at' => now()->subHours($hoursAgo),
        ])->save();
    });
}

it('lets a safe reply of an allowed intent go without a person', function () {
    $decision = autoSendDecision();

    expect($decision->allowed())->toBeTrue()
        ->and($decision->reasons)->toBe([]);
});

it('sends the reply to a person when', function (Closure $setup, string $reason) {
    $setup();

    $decision = autoSendDecision();

    expect($decision->allowed())->toBeFalse()
        ->and($decision->reason())->toContain($reason);
})->with([
    'el freno de emergencia está activado' => [fn () => config(['ai.automation.auto_send_enabled' => false]), 'desactivado en la plataforma'],
    'la empresa está en modo borradores' => [fn () => test()->tenant->configureAutomation(AutomationMode::Draft), 'modo «Borradores»'],
    'la empresa está sin automatización' => [fn () => test()->tenant->configureAutomation(AutomationMode::Off), 'modo «Desactivada»'],
    'la IA o sus reglas piden revisión' => [fn () => test()->reply->forceFill(['needs_human' => true, 'needs_human_reason' => 'Confianza baja (0.60 < 0.75).'])->save(), 'Necesita revisión: Confianza baja (0.60 < 0.75).'],
    'la intención no está permitida' => [fn () => test()->email->forceFill(['intent' => EmailIntent::ReturnRequest])->save(), 'intención «return_request»'],
    'el email no está clasificado' => [fn () => test()->email->forceFill(['intent' => null])->save(), 'no está clasificado'],
    'es un correo automático' => [fn () => test()->email->forceFill(['automated_reason' => 'Lista de correo o boletín.'])->save(), 'correo automático: Lista de correo'],
    'hay sospecha de injection' => [fn () => test()->email->forceFill(['injection_suspected' => true])->save(), 'instrucciones a la IA'],
    'el remitente no acepta respuestas' => [fn () => test()->email->forceFill(['from_email' => 'no-reply@banco.test'])->save(), 'no-reply@banco.test no acepta respuestas'],
    'no hay remitente' => [fn () => test()->email->forceFill(['from_email' => null])->save(), 'no tiene remitente'],
]);

it('respects the intents chosen by the company', function () {
    $this->tenant->configureAutomation(AutomationMode::Auto, ['invoice_request']);

    expect(autoSendDecision()->reason())->toContain('intención «order_status»');

    inTenant($this->tenant, fn () => $this->email->forceFill(['intent' => EmailIntent::InvoiceRequest])->save());

    expect(autoSendDecision()->allowed())->toBeTrue();
});

it('never answers one of the company own mailboxes', function () {
    inTenant($this->tenant, fn () => $this->email->forceFill(['from_email' => 'SOPORTE@empresa.test'])->save());

    expect(autoSendDecision()->reason())->toContain('buzón de la propia empresa (soporte@empresa.test)');
});

it('checks the reply-to address, which is where the reply goes', function () {
    inTenant($this->tenant, fn () => $this->email->recipients()->create([
        'type' => RecipientType::ReplyTo, 'email' => 'noreply@cliente.test', 'name' => null, 'position' => 0,
    ]));

    expect(autoSendDecision()->reason())->toContain('noreply@cliente.test no acepta respuestas');
});

it('stops answering the same address after the daily limit (loop protection)', function () {
    sentAutoReply('cliente@cliente.test');
    sentAutoReply('Cliente@Cliente.test');

    expect(autoSendDecision()->allowed())->toBeTrue();

    sentAutoReply('cliente@cliente.test');

    expect(autoSendDecision()->reason())->toContain('Ya se han enviado 3 respuestas automáticas a cliente@cliente.test en 24 horas');
});

it('only counts recent automatic replies of the same company', function () {
    config(['ai.automation.max_auto_replies_per_address' => 1]);

    sentAutoReply('cliente@cliente.test', hoursAgo: 25);
    sentAutoReply('cliente@cliente.test', automatic: false);
    sentAutoReply('cliente@cliente.test', tenant: Tenant::factory()->create());
    sentAutoReply('otro@cliente.test');

    expect(autoSendDecision()->allowed())->toBeTrue();
});

it('gives every reason at once', function () {
    $this->tenant->configureAutomation(AutomationMode::Draft);
    inTenant($this->tenant, function () {
        $this->email->forceFill(['injection_suspected' => true, 'intent' => EmailIntent::Other])->save();
        $this->reply->forceFill(['needs_human' => true, 'needs_human_reason' => 'Caso raro'])->save();
    });

    expect(autoSendDecision()->reasons)->toHaveCount(4);
});
