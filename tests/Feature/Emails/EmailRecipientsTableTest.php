<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function insertRecipientRow(int $emailId, array $overrides = []): int
{
    return DB::table('email_recipients')->insertGetId(array_merge([
        'tenant_id' => DB::table('emails')->where('id', $emailId)->value('tenant_id'),
        'email_id' => $emailId,
        'type' => 'to',
        'email' => 'soporte@empresa.test',
    ], $overrides));
}

it('has the expected columns', function () {
    expect(Schema::hasColumns('email_recipients', [
        'id', 'tenant_id', 'email_id', 'type', 'email', 'name', 'position',
    ]))->toBeTrue();
});

it('stores several recipients of each type for an email', function () {
    $emailId = insertEmailRow();

    insertRecipientRow($emailId, ['email' => 'a@empresa.test', 'name' => 'Ana', 'position' => 0]);
    insertRecipientRow($emailId, ['email' => 'b@empresa.test', 'position' => 1]);
    insertRecipientRow($emailId, ['type' => 'cc', 'email' => 'jefe@empresa.test']);

    expect(DB::table('email_recipients')->where('email_id', $emailId)->where('type', 'to')->orderBy('position')->pluck('email')->all())
        ->toBe(['a@empresa.test', 'b@empresa.test'])
        ->and(DB::table('email_recipients')->where('email_id', $emailId)->where('type', 'cc')->count())->toBe(1);
});

it('deletes the recipients together with the email', function () {
    $emailId = insertEmailRow();
    insertRecipientRow($emailId);

    DB::table('emails')->where('id', $emailId)->delete();

    expect(DB::table('email_recipients')->count())->toBe(0);
});

it('requires an existing email', function () {
    DB::table('email_recipients')->insert([
        'tenant_id' => DB::table('emails')->where('id', insertEmailRow())->value('tenant_id'),
        'email_id' => 999999, 'type' => 'to', 'email' => 'x@x.test',
    ]);
})->throws(QueryException::class);
