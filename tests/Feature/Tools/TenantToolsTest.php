<?php

use App\Models\Tenant;
use App\Models\TenantTool;
use App\Tools\TenantToolService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/*
 * Herramientas de la IA por empresa: las del catálogo compartido con
 * ai-service (contracts/tools/catalog.json) que cada empresa activa.
 */

beforeEach(function () {
    $this->shop = Tenant::factory()->create(['slug' => 'empresa-demo']);
    $this->clinic = Tenant::factory()->create(['slug' => 'clinica']);
});

/** @return list<string> */
function toolNamesFor(Tenant $tenant): array
{
    return app(TenantToolService::class)->enabledNames($tenant);
}

it('has the expected table', function () {
    expect(Schema::hasColumns('tenant_tools', ['id', 'tenant_id', 'tool', 'enabled', 'created_at', 'updated_at']))->toBeTrue();
});

it('gives every company the default tools only', function () {
    expect(toolNamesFor($this->shop))->toBe(['search_company_documents'])
        ->and(toolNamesFor($this->clinic))->toBe(['search_company_documents']);
});

it('adds the tools each company enables, in a stable order', function () {
    enableTools($this->shop, 'get_order', 'get_customer_orders');

    expect(toolNamesFor($this->shop))->toBe(['get_customer_orders', 'get_order', 'search_company_documents'])
        ->and(toolNamesFor($this->clinic))->toBe(['search_company_documents']);
});

it('lets a company disable a default tool', function () {
    app(TenantToolService::class)->disable($this->clinic, 'search_company_documents');

    expect(toolNamesFor($this->clinic))->toBe([])
        ->and(toolNamesFor($this->shop))->toBe(['search_company_documents']);
});

it('does not accept tools that are not in the catalog', function () {
    app(TenantToolService::class)->enable($this->shop, 'delete_customers');
})->throws(InvalidArgumentException::class);

it('keeps the settings of each company separate', function () {
    enableTools($this->shop, 'get_order');

    expect(inTenant($this->clinic, fn () => TenantTool::count()))->toBe(0)
        ->and(inTenant($this->shop, fn () => TenantTool::sole()->tool))->toBe('get_order');
});

it('shows and manages the tools of a company from the console', function () {
    Http::fake(['ai-service.test/*' => Http::response(['tools' => [
        ['name' => 'get_order', 'available' => false],
        ['name' => 'search_company_documents', 'available' => true],
    ]])]);

    $this->artisan('ai:tools', ['tenant' => $this->clinic->id, '--enable' => ['get_order']])
        ->expectsOutputToContain('no (sin datos configurados)')
        ->assertSuccessful();

    $this->artisan('ai:tools', ['tenant' => $this->shop->id, '--enable' => ['get_order'], '--disable' => ['search_company_documents']])
        ->assertSuccessful();

    expect(toolNamesFor($this->shop))->toBe(['get_order']);

    $this->artisan('ai:tools', ['tenant' => $this->shop->id, '--enable' => ['inventada']])->assertFailed();
    $this->artisan('ai:tools', ['tenant' => 999999])->assertFailed();
});

it('says when ai-service does not answer about the data', function () {
    Http::fake(['ai-service.test/*' => Http::response([], 503)]);

    $this->artisan('ai:tools', ['tenant' => $this->shop->id])
        ->expectsOutputToContain('ai-service no responde')
        ->assertSuccessful();
});
