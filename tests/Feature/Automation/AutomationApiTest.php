<?php

use App\Automation\AutomationMode;
use App\Models\Tenant;
use App\Models\TenantTool;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->admin = User::factory()->admin()->for($this->tenant)->create();
    $this->agent = User::factory()->for($this->tenant)->create();
});

/** @return Tests\TestCase El test, ya autenticado como $user (test() devuelve un proxy de Pest). */
function asUser(User $user): mixed
{
    Sanctum::actingAs($user);

    return test();
}

it('shows the ai configuration of the company', function () {
    config(['ai.anthropic.api_key' => 'sk-ant-x', 'ai.anthropic.model' => 'claude-opus-5-5']);

    $response = asUser($this->agent)->getJson('/api/v1/automation')->assertOk();

    $response->assertJsonPath('data.mode', 'draft')
        ->assertJsonPath('data.modes.1', ['value' => 'draft', 'label' => 'Borradores', 'description' => AutomationMode::Draft->description()])
        ->assertJsonPath('data.auto_send_intents', ['order_status', 'product_question'])
        ->assertJsonPath('data.auto_send_intents_custom', false)
        ->assertJsonPath('data.platform.ai_configured', true)
        ->assertJsonPath('data.platform.auto_send_enabled', true)
        ->assertJsonPath('data.platform.model', 'claude-opus-5-5')
        ->assertJsonPath('data.platform.min_confidence', 0.75);

    expect(collect($response->json('data.intents'))->firstWhere('value', 'complaint'))->toBe(['value' => 'complaint', 'allows_auto_send' => false])
        ->and(collect($response->json('data.tools'))->firstWhere('tool', 'search_company_documents'))
        ->toBe(['tool' => 'search_company_documents', 'enabled' => true, 'available' => true, 'source' => 'default']);
});

it('lets an administrator change the mode and the intents', function () {
    asUser($this->admin)->patchJson('/api/v1/automation', ['mode' => 'auto', 'auto_send_intents' => ['order_status', 'invoice_request']])
        ->assertOk()
        ->assertJsonPath('data.mode', 'auto')
        ->assertJsonPath('data.auto_send_intents', ['order_status', 'invoice_request'])
        ->assertJsonPath('data.auto_send_intents_custom', true);

    expect($this->tenant->fresh()->automation_mode)->toBe(AutomationMode::Auto);

    // Solo el modo: las intenciones se mantienen. [] → las de la plataforma.
    asUser($this->admin)->patchJson('/api/v1/automation', ['mode' => 'draft'])->assertJsonPath('data.auto_send_intents', ['order_status', 'invoice_request']);
    asUser($this->admin)->patchJson('/api/v1/automation', ['auto_send_intents' => []])
        ->assertJsonPath('data.mode', 'draft')
        ->assertJsonPath('data.auto_send_intents_custom', false);
});

it('never lets the company allow automatic answers to complaints or billing issues', function () {
    asUser($this->admin)->patchJson('/api/v1/automation', ['mode' => 'auto', 'auto_send_intents' => ['complaint']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['auto_send_intents' => 'siempre lo revisa una persona']);

    expect($this->tenant->fresh()->automation_mode)->toBe(AutomationMode::Draft);
});

it('rejects invalid values', function (array $body, string $field) {
    asUser($this->admin)->patchJson('/api/v1/automation', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'modo desconocido' => [['mode' => 'turbo'], 'mode'],
    'intenciones que no son lista' => [['auto_send_intents' => 'order_status'], 'auto_send_intents'],
    'intención desconocida' => [['auto_send_intents' => ['reembolsar']], 'auto_send_intents'],
]);

it('lets an administrator turn the agent tools on and off', function () {
    asUser($this->admin)->patchJson('/api/v1/automation/tools/get_order', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.tools', fn (array $tools) => collect($tools)->firstWhere('tool', 'get_order') === ['tool' => 'get_order', 'enabled' => true, 'available' => true, 'source' => 'company']);

    asUser($this->admin)->patchJson('/api/v1/automation/tools/search_company_documents', ['enabled' => false])->assertOk();

    expect(inTenant($this->tenant, fn () => TenantTool::orderBy('tool')->pluck('enabled', 'tool')->all()))
        ->toBe(['get_order' => true, 'search_company_documents' => false]);
});

it('does not know tools outside the catalog', function () {
    asUser($this->admin)->patchJson('/api/v1/automation/tools/run_sql', ['enabled' => true])->assertNotFound();
    asUser($this->admin)->patchJson('/api/v1/automation/tools/get_order', [])->assertUnprocessable()->assertJsonValidationErrors('enabled');
});

it('only lets owners and administrators change it', function () {
    asUser($this->agent)->patchJson('/api/v1/automation', ['mode' => 'auto'])->assertForbidden();
    asUser($this->agent)->patchJson('/api/v1/automation/tools/get_order', ['enabled' => true])->assertForbidden();

    expect($this->tenant->fresh()->automation_mode)->toBe(AutomationMode::Draft);
});

it('only changes the company of the user', function () {
    $other = Tenant::factory()->create();

    asUser($this->admin)->patchJson('/api/v1/automation', ['mode' => 'auto'])->assertOk();
    asUser($this->admin)->patchJson('/api/v1/automation/tools/get_order', ['enabled' => true])->assertOk();

    expect($other->fresh()->automation_mode)->toBe(AutomationMode::Draft)
        ->and(inTenant($other, fn () => TenantTool::count()))->toBe(0);
});

it('requires a session', function () {
    $this->getJson('/api/v1/automation')->assertUnauthorized();
});
