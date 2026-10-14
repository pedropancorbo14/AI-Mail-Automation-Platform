<?php

use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\Tenant;
use App\Models\TenantTool;
use App\Tools\Contracts\AITool;
use App\Tools\Enums\ToolCallStatus;
use App\Tools\TenantToolService;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->shop = Tenant::factory()->create(['slug' => 'empresa-demo']);   // tiene pedidos de demostración
    $this->clinic = Tenant::factory()->create(['slug' => 'clinica']);      // no tiene pedidos
});

/** @return list<string> */
function toolNamesFor(Tenant $tenant): array
{
    return array_map(fn (AITool $tool) => $tool->name(), app(TenantToolService::class)->toolsFor($tenant));
}

it('has the expected table', function () {
    expect(Schema::hasColumns('tenant_tools', ['id', 'tenant_id', 'tool', 'enabled', 'created_at', 'updated_at']))->toBeTrue();
});

it('gives every company the default tools only', function () {
    expect(toolNamesFor($this->shop))->toBe(['search_company_documents'])
        ->and(toolNamesFor($this->clinic))->toBe(['search_company_documents']);
});

it('offers order tools only to companies that enable them and have orders', function () {
    enableTools($this->shop, 'get_order', 'get_customer_orders');
    enableTools($this->clinic, 'get_order');

    expect(toolNamesFor($this->shop))->toBe(['get_customer_orders', 'get_order', 'search_company_documents'])
        ->and(toolNamesFor($this->clinic))->toBe(['search_company_documents']);
});

it('lets a company disable a default tool', function () {
    app(TenantToolService::class)->disable($this->clinic, 'search_company_documents');

    expect(toolNamesFor($this->clinic))->toBe([])
        ->and(toolNamesFor($this->shop))->toBe(['search_company_documents']);
});

it('rejects tools that are not enabled for the company even if they exist', function () {
    $email = inTenant($this->shop, fn () => Email::factory()->create(['from_email' => 'cliente@cliente.test']));

    $result = app(ToolExecutor::class)->execute('get_order', ['order_number' => '45821'], new ToolContext($this->shop, $email));

    expect($result->error)->toBe('La herramienta get_order no está disponible para esta empresa.')
        ->and(inTenant($this->shop, fn () => AiToolCall::sole()->status))->toBe(ToolCallStatus::Rejected);
});

it('does not accept unknown tools', function () {
    app(TenantToolService::class)->enable($this->shop, 'delete_customers');
})->throws(InvalidArgumentException::class);

it('keeps the settings of each company separate', function () {
    enableTools($this->shop, 'get_order');

    expect(inTenant($this->clinic, fn () => TenantTool::count()))->toBe(0)
        ->and(inTenant($this->shop, fn () => TenantTool::sole()->tool))->toBe('get_order');
});

it('shows and manages the tools of a company from the console', function () {
    $this->artisan('ai:tools', ['tenant' => $this->clinic->id, '--enable' => ['get_order']])
        ->expectsOutputToContain('no (sin datos configurados)')
        ->assertSuccessful();

    $this->artisan('ai:tools', ['tenant' => $this->shop->id, '--enable' => ['get_order'], '--disable' => ['search_company_documents']])
        ->assertSuccessful();

    expect(toolNamesFor($this->shop))->toBe(['get_order']);

    $this->artisan('ai:tools', ['tenant' => $this->shop->id, '--enable' => ['inventada']])->assertFailed();
    $this->artisan('ai:tools', ['tenant' => 999999])->assertFailed();
});
