<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function insertConnection(array $overrides = []): int
{
    return DB::table('mail_connections')->insertGetId(array_merge([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Soporte',
        'provider' => 'imap',
        'email' => 'soporte@acme.test',
        'credentials' => 'cifrado',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('has the expected columns', function () {
    expect(Schema::hasColumns('mail_connections', [
        'id', 'tenant_id', 'created_by', 'name', 'provider', 'email', 'credentials',
        'status', 'last_tested_at', 'last_error', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('starts connections as pending', function () {
    $id = insertConnection();

    expect(DB::table('mail_connections')->where('id', $id)->value('status'))->toBe('pending');
});

it('does not allow the same account twice in a company', function () {
    $tenant = Tenant::factory()->create();

    insertConnection(['tenant_id' => $tenant->id]);
    insertConnection(['tenant_id' => $tenant->id]);
})->throws(QueryException::class);

it('allows the same account in different companies', function () {
    insertConnection();
    insertConnection();

    expect(DB::table('mail_connections')->count())->toBe(2);
});

it('requires an existing tenant', function () {
    insertConnection(['tenant_id' => 999999]);
})->throws(QueryException::class);

it('keeps the connection when its creator is deleted', function () {
    $user = User::factory()->create();
    $id = insertConnection(['tenant_id' => $user->tenant_id, 'created_by' => $user->id]);

    $user->delete();

    expect(DB::table('mail_connections')->where('id', $id)->value('created_by'))->toBeNull();
});
