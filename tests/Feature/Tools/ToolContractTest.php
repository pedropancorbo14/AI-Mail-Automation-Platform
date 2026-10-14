<?php

use App\Models\Email;
use App\Models\Tenant;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

it('describes a successful result as data for the model', function () {
    $result = ToolResult::success(['order' => '45821', 'status' => 'shipped']);

    expect($result->ok)->toBeTrue()
        ->and($result->toArray())->toBe(['order' => '45821', 'status' => 'shipped']);
});

it('describes a failure as an error message for the model', function () {
    $result = ToolResult::error('No existe ningún pedido con ese número para este cliente.');

    expect($result->ok)->toBeFalse()
        ->and($result->toArray())->toBe(['error' => 'No existe ningún pedido con ese número para este cliente.']);
});

it('takes the sender from the email being processed, never from the model', function () {
    $tenant = Tenant::factory()->create();
    $email = inTenant($tenant, fn () => Email::factory()->create(['from_email' => '  Cliente@Cliente.TEST ']));

    expect((new ToolContext($tenant, $email))->senderEmail())->toBe('cliente@cliente.test')
        ->and((new ToolContext($tenant))->senderEmail())->toBeNull();
});
