<?php

use App\Models\MailConnection;
use App\Models\MailSyncState;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->connection = inTenant($this->tenant, fn () => MailConnection::factory()->create());
    $this->state = inTenant($this->tenant, fn () => $this->connection->syncStates()->create(['folder' => 'INBOX']));
});

it('starts without cursor nor errors', function () {
    $state = $this->state->fresh();

    expect($state->tenant_id)->toBe($this->tenant->id)
        ->and($state->cursor)->toBeNull()
        ->and($state->last_synced_at)->toBeNull()
        ->and($state->consecutive_failures)->toBe(0);
});

it('keeps one state per mailbox folder', function () {
    inTenant($this->tenant, fn () => $this->connection->syncStates()->create(['folder' => 'INBOX']));
})->throws(QueryException::class);

it('records failures without moving the cursor', function () {
    $this->freezeSecond();
    inTenant($this->tenant, function () {
        $this->state->recordSuccess('v1:1:10');
        $this->state->recordFailure('No se pudo conectar con imap.example.test');
        $this->state->recordFailure(str_repeat('x', 5000));
    });

    $state = $this->state->fresh();

    expect($state->cursor)->toBe('v1:1:10')
        ->and($state->consecutive_failures)->toBe(2)
        ->and(mb_strlen($state->last_error))->toBeLessThanOrEqual(1003)
        ->and($state->last_synced_at->eq(now()))->toBeTrue();
});

it('records a success moving the cursor and clearing the error', function () {
    $this->freezeSecond();
    inTenant($this->tenant, function () {
        $this->state->recordFailure('fallo');
        $this->state->recordSuccess('v1:1:20');
    });

    $state = $this->state->fresh();

    expect($state->cursor)->toBe('v1:1:20')
        ->and($state->last_error)->toBeNull()
        ->and($state->consecutive_failures)->toBe(0)
        ->and($state->last_success_at->eq(now()))->toBeTrue()
        ->and($state->resynced_at)->toBeNull();
});

it('records when the provider invalidated the cursor', function () {
    $this->freezeSecond();
    inTenant($this->tenant, fn () => $this->state->recordSuccess('v1:2:0', resynced: true));

    expect($this->state->fresh()->resynced_at->eq(now()))->toBeTrue();
});

it('is deleted together with its mailbox', function () {
    inTenant($this->tenant, fn () => $this->connection->delete());

    expect(inTenant($this->tenant, fn () => MailSyncState::count()))->toBe(0);
});
