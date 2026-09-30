<?php

use App\Mail\Enums\EmailStatus;
use App\Models\Email;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantException;

it('belongs to the current tenant and its mail connection', function () {
    $tenant = Tenant::factory()->create();

    $email = inTenant($tenant, fn () => Email::factory()->create()->load('mailConnection'));

    expect($email->tenant_id)->toBe($tenant->id)
        ->and($email->mailConnection->tenant_id)->toBe($tenant->id)
        ->and(inTenant($tenant, fn () => $email->mailConnection->emails()->pluck('id')->all()))->toBe([$email->id]);
});

it('casts status, dates and references', function () {
    $email = inTenant(Tenant::factory()->create(), fn () => Email::factory()->create([
        'references' => ['a@x.test', 'b@x.test'],
        'sent_at' => '2026-09-30 10:15:00',
    ])->fresh());

    expect($email->status)->toBe(EmailStatus::Received)
        ->and($email->references)->toBe(['a@x.test', 'b@x.test'])
        ->and($email->sent_at->toDateTimeString())->toBe('2026-09-30 10:15:00')
        ->and($email->ingested_at)->toBeNull();
});

it('only shows emails of the current tenant', function () {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();
    $mine = inTenant($acme, fn () => Email::factory()->create());
    inTenant($globex, fn () => Email::factory()->create());

    expect(inTenant($acme, fn () => Email::pluck('id')->all()))->toBe([$mine->id]);
});

it('fails closed without tenant', function () {
    Email::count();
})->throws(MissingTenantException::class);

it('does not mass assign status, ingestion date or tenant', function () {
    $email = new Email(['status' => 'sent', 'ingested_at' => now(), 'tenant_id' => 99, 'subject' => 'Hola']);

    expect($email->status)->toBe(EmailStatus::Received)
        ->and($email->ingested_at)->toBeNull()
        ->and($email->tenant_id)->toBeNull()
        ->and($email->subject)->toBe('Hola');
});
