<?php

use App\Mail\Enums\AttachmentStatus;
use App\Mail\Enums\RecipientType;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\EmailRecipient;
use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->email = inTenant($this->tenant, fn () => Email::factory()->create());
});

it('stores the recipients of an email in the email tenant', function () {
    $recipients = inTenant($this->tenant, function () {
        $this->email->recipients()->createMany([
            ['type' => RecipientType::Cc, 'email' => 'jefe@empresa.test'],
            ['type' => RecipientType::To, 'email' => 'b@empresa.test', 'position' => 1],
            ['type' => RecipientType::To, 'email' => 'a@empresa.test', 'name' => 'Ana', 'position' => 0],
        ]);

        return $this->email->recipients()->get();
    });

    expect($recipients->map(fn (EmailRecipient $r) => [$r->type, $r->email])->all())->toBe([
        [RecipientType::Cc, 'jefe@empresa.test'],
        [RecipientType::To, 'a@empresa.test'],
        [RecipientType::To, 'b@empresa.test'],
    ])->and($recipients->pluck('tenant_id')->unique()->all())->toBe([$this->tenant->id]);
});

it('stores attachment metadata as pending without exposing the file path', function () {
    $attachment = inTenant($this->tenant, fn () => $this->email->attachments()->create([
        'provider_attachment_id' => '2',
        'filename' => 'factura.pdf',
        'content_type' => 'application/pdf',
        'size' => 2048,
        'inline' => false,
    ])->fresh());

    expect($attachment->status)->toBe(AttachmentStatus::Pending)
        ->and($attachment->tenant_id)->toBe($this->tenant->id)
        ->and($attachment->size)->toBe(2048)
        ->and($attachment->toArray())->not->toHaveKeys(['disk', 'path']);
});

it('does not mass assign the stored file', function () {
    $attachment = new EmailAttachment(['filename' => 'x.pdf', 'status' => 'stored', 'path' => '../../etc/passwd']);

    expect($attachment->status)->toBe(AttachmentStatus::Pending)
        ->and($attachment->path)->toBeNull();
});

it('isolates recipients and attachments by tenant', function () {
    inTenant($this->tenant, function () {
        $this->email->recipients()->create(['type' => RecipientType::To, 'email' => 'a@empresa.test']);
        $this->email->attachments()->create(['provider_attachment_id' => '1', 'filename' => 'a', 'content_type' => 'text/plain']);
    });

    $other = Tenant::factory()->create();

    expect(inTenant($other, fn () => [EmailRecipient::count(), EmailAttachment::count()]))->toBe([0, 0]);
    EmailRecipient::count();
})->throws(MissingTenantException::class);
