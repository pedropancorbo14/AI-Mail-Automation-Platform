<?php

use App\Models\MailConnection;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has the expected columns', function () {
    expect(Schema::hasColumns('emails', [
        'id', 'tenant_id', 'mail_connection_id', 'folder', 'provider_message_id',
        'message_id', 'in_reply_to', 'references', 'dedup_hash',
        'from_email', 'from_name', 'subject', 'text_body', 'html_body', 'sent_at',
        'status', 'ingested_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('starts emails as received and not ingested', function () {
    $email = DB::table('emails')->find(insertEmailRow());

    expect($email->status)->toBe('received')
        ->and($email->ingested_at)->toBeNull();
});

it('does not store the same email twice in a mailbox', function () {
    $id = insertEmailRow(['dedup_hash' => str_repeat('a', 64)]);
    $email = DB::table('emails')->find($id);

    insertEmailRow([
        'tenant_id' => $email->tenant_id,
        'mail_connection_id' => $email->mail_connection_id,
        'dedup_hash' => str_repeat('a', 64),
    ]);
})->throws(QueryException::class);

it('allows the same email in different mailboxes', function () {
    insertEmailRow(['dedup_hash' => str_repeat('a', 64)]);
    insertEmailRow(['dedup_hash' => str_repeat('a', 64)]);

    expect(DB::table('emails')->count())->toBe(2);
});

it('does not allow deleting a mailbox that has emails', function () {
    $email = DB::table('emails')->find(insertEmailRow());

    DB::table('mail_connections')->where('id', $email->mail_connection_id)->delete();
})->throws(QueryException::class);

it('stores long bodies and references as json', function () {
    $body = str_repeat('Texto largo. ', 200_000);
    $id = insertEmailRow(['text_body' => $body, 'references' => json_encode(['a@x.test', 'b@x.test'])]);

    $email = DB::table('emails')->find($id);

    expect($email->text_body)->toBe($body)
        ->and(json_decode($email->references))->toBe(['a@x.test', 'b@x.test']);
});
