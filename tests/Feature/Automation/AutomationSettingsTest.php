<?php

use App\AI\Classification\EmailIntent;
use App\Automation\AutomationMode;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['name' => 'Muebles Demo']);
});

it('starts in draft mode: nothing is sent without a person', function () {
    $tenant = $this->tenant->fresh();

    expect($tenant->automation_mode)->toBe(AutomationMode::Draft)
        ->and($tenant->auto_send_intents)->toBeNull()
        ->and($tenant->autoSendIntents())->toBe([EmailIntent::OrderStatus, EmailIntent::ProductQuestion]);
});

it('lets the company choose the mode and the intents', function () {
    $this->tenant->configureAutomation(AutomationMode::Auto, ['order_status', 'invoice_request', 'order_status']);

    $tenant = $this->tenant->fresh();

    expect($tenant->automation_mode)->toBe(AutomationMode::Auto)
        ->and($tenant->auto_send_intents)->toBe(['order_status', 'invoice_request'])
        ->and($tenant->autoSendIntents())->toBe([EmailIntent::OrderStatus, EmailIntent::InvoiceRequest]);
});

it('keeps the intents when only the mode changes, and resets them with an empty list', function () {
    $this->tenant->configureAutomation(AutomationMode::Auto, ['invoice_request']);
    $this->tenant->configureAutomation(AutomationMode::Draft);

    expect($this->tenant->fresh()->auto_send_intents)->toBe(['invoice_request']);

    $this->tenant->configureAutomation(AutomationMode::Auto, []);

    expect($this->tenant->fresh()->auto_send_intents)->toBeNull();
});

it('never lets the ai answer complaints, billing issues or unclear emails on its own', function (string $intent) {
    expect(fn () => $this->tenant->configureAutomation(AutomationMode::Auto, ['order_status', $intent]))
        ->toThrow(InvalidArgumentException::class, 'siempre lo revisa una persona');

    expect($this->tenant->fresh()->automation_mode)->toBe(AutomationMode::Draft);
})->with(['complaint', 'billing_issue', 'spam', 'auto_reply', 'other']);

it('rejects unknown intents', function () {
    $this->tenant->configureAutomation(AutomationMode::Auto, ['refund_everything']);
})->throws(InvalidArgumentException::class, 'no existe');

it('ignores intents that are not allowed even if they come from the configuration', function () {
    config(['ai.automation.default_auto_send_intents' => ['order_status', 'complaint', 'no_existe']]);

    expect($this->tenant->autoSendIntents())->toBe([EmailIntent::OrderStatus]);
});

it('shows the automation of a company', function () {
    $this->artisan('ai:automation', ['tenant' => $this->tenant->id])
        ->expectsOutputToContain('Automatización de «Muebles Demo»: Borradores (draft)')
        ->expectsOutputToContain('una persona la revisa siempre')
        ->expectsOutputToContain('SÍ (configuración por defecto)')
        ->expectsOutputToContain('nunca (siempre una persona)')
        ->expectsOutputToContain('no se envía nada sin revisión humana')
        ->assertSuccessful();
});

it('changes the mode and the intents from the command', function () {
    $this->artisan('ai:automation', ['tenant' => $this->tenant->id, '--mode' => 'auto', '--intents' => ['order_status,invoice_request']])
        ->expectsOutputToContain('Automática (auto)')
        ->expectsOutputToContain('SÍ (elegidas por la empresa)')
        ->assertSuccessful();

    expect($this->tenant->fresh())
        ->automation_mode->toBe(AutomationMode::Auto)
        ->auto_send_intents->toBe(['order_status', 'invoice_request']);

    $this->artisan('ai:automation', ['tenant' => $this->tenant->id, '--intents' => ['']])->assertSuccessful();

    expect($this->tenant->fresh())
        ->automation_mode->toBe(AutomationMode::Auto)
        ->auto_send_intents->toBeNull();
});

it('explains invalid input', function () {
    $this->artisan('ai:automation', ['tenant' => 999999])->expectsOutputToContain('No existe esa empresa')->assertFailed();
    $this->artisan('ai:automation', ['tenant' => $this->tenant->id, '--mode' => 'turbo'])->expectsOutputToContain('usa off, draft o auto')->assertFailed();
    $this->artisan('ai:automation', ['tenant' => $this->tenant->id, '--mode' => 'auto', '--intents' => ['complaint']])
        ->expectsOutputToContain('siempre lo revisa una persona')
        ->assertFailed();

    expect($this->tenant->fresh()->automation_mode)->toBe(AutomationMode::Draft);
});
