<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tools\TenantToolService;
use Database\Seeders\ClinicDemoSeeder;

it('creates the demo clinic with its owner and the appointment tools, once', function () {
    $this->seed(ClinicDemoSeeder::class);
    $this->seed(ClinicDemoSeeder::class); // idempotente

    $clinic = Tenant::where('slug', 'clinica-demo')->sole();
    $owner = User::where('email', 'clinica@example.com')->sole();

    expect($clinic->name)->toBe('Clínica Demo')
        ->and($owner->tenant_id)->toBe($clinic->id)
        ->and($owner->hasRole('owner'))->toBeTrue()
        ->and(app(TenantToolService::class)->enabledNames($clinic))
        ->toContain('get_customer_appointments', 'get_appointment', 'search_company_documents');

    // El servidor MCP la encuentra por su slug.
    config(['integrations.data_token' => 'token-de-datos']);
    $this->getJson("/internal/integrations/{$clinic->id}", ['Authorization' => 'Bearer token-de-datos'])
        ->assertJsonPath('tenant_slug', 'clinica-demo');
});
