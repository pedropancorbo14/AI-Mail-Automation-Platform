<?php

use App\Tools\ToolRegistry;
use Tests\Fakes\FakeTool;

it('registers tools and finds them by name', function () {
    $registry = new ToolRegistry([new FakeTool('get_order'), new FakeTool('search_company_documents')]);

    expect($registry->has('get_order'))->toBeTrue()
        ->and($registry->get('get_order')->name())->toBe('get_order')
        ->and($registry->get('delete_everything'))->toBeNull()
        ->and($registry->all())->toHaveCount(2);
});

it('gives the model the definitions in a stable order', function () {
    $registry = new ToolRegistry([new FakeTool('search_company_documents', description: 'Busca'), new FakeTool('get_order', description: 'Pedido')]);

    expect($registry->definitions())->toBe([
        ['name' => 'get_order', 'description' => 'Pedido', 'input_schema' => (new FakeTool())->inputSchema()],
        ['name' => 'search_company_documents', 'description' => 'Busca', 'input_schema' => (new FakeTool())->inputSchema()],
    ]);
});

it('rejects invalid or duplicated names', function (Closure $build) {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'mayúsculas' => fn () => new ToolRegistry([new FakeTool('GetOrder')]),
    'espacios' => fn () => new ToolRegistry([new FakeTool('get order')]),
    'duplicado' => fn () => new ToolRegistry([new FakeTool('get_order'), new FakeTool('get_order')]),
]);

it('only accepts closed object schemas', function () {
    new ToolRegistry([new FakeTool('loose', ['type' => 'object', 'properties' => []])]);
})->throws(InvalidArgumentException::class, 'additionalProperties');

it('builds the registry from the configured tools', function () {
    config(['ai.tools.available' => [FakeTool::class]]);
    app()->forgetInstance(ToolRegistry::class);

    expect(app(ToolRegistry::class)->has('echo'))->toBeTrue();
});
