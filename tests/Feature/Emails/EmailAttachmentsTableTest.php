<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function insertAttachmentRow(int $emailId, array $overrides = []): int
{
    return DB::table('email_attachments')->insertGetId(array_merge([
        'tenant_id' => DB::table('emails')->where('id', $emailId)->value('tenant_id'),
        'email_id' => $emailId,
        'provider_attachment_id' => '1',
        'filename' => 'factura.pdf',
        'content_type' => 'application/pdf',
        'size' => 1024,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('has the expected columns', function () {
    expect(Schema::hasColumns('email_attachments', [
        'id', 'tenant_id', 'email_id', 'provider_attachment_id', 'filename', 'content_type',
        'size', 'inline', 'content_id', 'status', 'disk', 'path', 'sha256', 'error',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('starts attachments as pending and without file', function () {
    $attachment = DB::table('email_attachments')->find(insertAttachmentRow(insertEmailRow()));

    expect($attachment->status)->toBe('pending')
        ->and($attachment->path)->toBeNull()
        ->and($attachment->inline)->toBeFalse();
});

it('does not store the same attachment of an email twice', function () {
    $emailId = insertEmailRow();

    insertAttachmentRow($emailId, ['provider_attachment_id' => '2']);
    insertAttachmentRow($emailId, ['provider_attachment_id' => '2']);
})->throws(QueryException::class);

it('stores sizes above 2 GB', function () {
    $id = insertAttachmentRow(insertEmailRow(), ['size' => 3 * 1024 ** 3]);

    expect((int) DB::table('email_attachments')->where('id', $id)->value('size'))->toBe(3 * 1024 ** 3);
});

it('deletes the attachments together with the email', function () {
    $emailId = insertEmailRow();
    insertAttachmentRow($emailId);

    DB::table('emails')->where('id', $emailId)->delete();

    expect(DB::table('email_attachments')->count())->toBe(0);
});
