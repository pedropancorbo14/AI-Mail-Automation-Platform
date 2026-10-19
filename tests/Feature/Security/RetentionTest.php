<?php

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Mail\Enums\AttachmentStatus;
use App\Mail\Enums\EmailStatus;
use App\Mail\Enums\RecipientType;
use App\Models\AiAgentRun;
use App\Models\AiRequest;
use App\Models\AiToolCall;
use App\Models\AuditLog;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\EmailReply;
use App\Models\EmailStatusChange;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Retention\EmailEraser;
use App\Security\Retention\RetentionService;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Retención y derecho de supresión: borrar emails con todo lo personal que
 * cuelga de ellos, sin perder los costes ni la auditoría.
 */

beforeEach(function () {
    Storage::fake('local');
    config(['retention.emails_days' => 365, 'retention.audit_days' => 730]);
    $this->tenant = Tenant::factory()->create(['name' => 'Acme']);
});

/** Email completo: destinatario, adjunto en disco, propuesta, historial y rastro de la IA. */
function fullEmail(Tenant $tenant, array $attributes = []): Email
{
    return inTenant($tenant, function () use ($tenant, $attributes) {
        $email = Email::factory()->create(['from_email' => 'ana@cliente.test', ...$attributes]);
        $email->recipients()->create(['type' => RecipientType::Cc, 'email' => 'jefe@cliente.test', 'position' => 0]);

        $attachment = $email->attachments()->create(['provider_attachment_id' => '1', 'filename' => 'factura.pdf', 'content_type' => 'application/pdf', 'size' => 4]);
        $path = "tenants/{$tenant->id}/emails/{$email->id}/{$attachment->id}";
        Storage::disk('local')->put($path, '%PDF');
        $attachment->forceFill(['status' => AttachmentStatus::Stored, 'disk' => 'local', 'path' => $path])->save();

        $run = AiAgentRun::create(['email_id' => $email->id, 'status' => 'completed', 'final_output' => ['reply_body' => 'Hola Ana']]);
        $step = AiRequest::create([
            'email_id' => $email->id, 'agent_run_id' => $run->id, 'purpose' => 'email_agent', 'provider' => 'anthropic',
            'requested_model' => 'claude-opus-5-5', 'status' => 'success', 'cost_usd' => 0.05, 'input_tokens' => 1000,
            'response' => ['summary' => 'Ana pregunta por su pedido'],
        ]);
        AiToolCall::create(['ai_request_id' => $step->id, 'email_id' => $email->id, 'tool' => 'get_order', 'arguments' => ['order_number' => '45821'], 'status' => 'success', 'result' => ['customer' => 'Ana']]);
        draftReply($email, ['body' => 'Hola Ana']);
        $email->transitionTo(EmailStatus::Processing, 'prueba');
        $email->transitionTo(EmailStatus::HumanReview, 'prueba');

        return $email;
    });
}

it('erases an email with everything personal hanging from it, keeping the ai costs', function () {
    $email = fullEmail($this->tenant);
    $path = inTenant($this->tenant, fn () => $email->attachments()->first()->path);

    inTenant($this->tenant, fn () => app(EmailEraser::class)->erase($email));

    inTenant($this->tenant, function () use ($email) {
        expect(Email::find($email->id))->toBeNull()
            ->and($email->recipients()->count())->toBe(0)
            ->and(EmailAttachment::where('email_id', $email->id)->count())->toBe(0)
            ->and(EmailReply::where('email_id', $email->id)->count())->toBe(0)
            ->and(EmailStatusChange::where('email_id', $email->id)->count())->toBe(0);

        $request = AiRequest::sole();
        expect($request->email_id)->toBeNull()
            ->and($request->response)->toBeNull()
            ->and((float) $request->cost_usd)->toBe(0.05)
            ->and($request->input_tokens)->toBe(1000);

        $call = AiToolCall::sole();
        expect($call->arguments)->toBe([])->and($call->result)->toBeNull()->and($call->tool)->toBe('get_order');
        expect(AiAgentRun::sole()->final_output)->toBeNull();
    });

    Storage::disk('local')->assertMissing($path);
    Storage::disk('local')->assertDirectoryEmpty("tenants/{$this->tenant->id}/emails");
});

it('never erases an email that is being processed or sent', function (string $state) {
    $email = fullEmail($this->tenant);
    inTenant($this->tenant, fn () => $state === 'sending'
        ? $email->replies()->first()->forceFill(['status' => EmailReplyStatus::Sending])->save()
        : $email->forceFill(['status' => EmailStatus::from($state)])->save());

    expect(fn () => inTenant($this->tenant, fn () => app(EmailEraser::class)->erase($email->fresh())))
        ->toThrow(RuntimeException::class, 'se está procesando o enviando');
})->with(['processing', 'approved', 'sending']);

it('prunes emails older than the retention of each company and records it', function () {
    $old = fullEmail($this->tenant, ['created_at' => now()->subDays(400)]);
    $recent = fullEmail($this->tenant, ['created_at' => now()->subDays(100)]);
    $strict = Tenant::factory()->create(['slug' => 'estricta']);
    $strict->forceFill(['email_retention_days' => 30])->save();
    $strictOld = fullEmail($strict, ['created_at' => now()->subDays(45)]);
    $forever = Tenant::factory()->create();
    $forever->forceFill(['email_retention_days' => null])->save();

    $this->artisan('data:prune')->expectsOutputToContain('Registros de auditoría borrados')->assertSuccessful();

    expect(inTenant($this->tenant, fn () => Email::pluck('id')->all()))->toBe([$recent->id])
        ->and(inTenant($strict, fn () => Email::count()))->toBe(0)
        ->and(inTenant($this->tenant, fn () => AuditLog::sole()))
        ->action->toBe('retention.emails_purged')
        ->metadata->toEqual(['emails' => 1, 'days' => 365]);
    expect($old->exists && $strictOld->exists)->toBeTrue();
});

it('keeps everything when the retention is 0', function () {
    config(['retention.emails_days' => 0]);
    fullEmail($this->tenant, ['created_at' => now()->subYears(5)]);

    expect(app(RetentionService::class)->pruneEmails($this->tenant))->toBe(0)
        ->and(inTenant($this->tenant, fn () => Email::count()))->toBe(1);
});

it('prunes old audit logs', function () {
    DB::table('audit_logs')->insert([
        ['tenant_id' => $this->tenant->id, 'action' => 'user.created', 'created_at' => now()->subDays(800)],
        ['tenant_id' => $this->tenant->id, 'action' => 'user.updated', 'created_at' => now()->subDays(10)],
    ]);

    expect(app(RetentionService::class)->pruneAuditLogs())->toBe(1)
        ->and(AuditLog::withoutGlobalScope(TenantScope::class)->pluck('action')->all())->toBe(['user.updated']);
});

it('forgets every email of an address, as sender or recipient, only in that company', function () {
    fullEmail($this->tenant, ['from_email' => 'Ana@Cliente.test']);
    fullEmail($this->tenant, ['from_email' => 'otro@cliente.test']); // no la menciona: se queda
    $cc = fullEmail($this->tenant, ['from_email' => 'tercero@cliente.test']);
    inTenant($this->tenant, fn () => $cc->recipients()->create(['type' => RecipientType::To, 'email' => 'ana@cliente.test', 'position' => 1]));
    $other = Tenant::factory()->create();
    fullEmail($other, ['from_email' => 'ana@cliente.test']);

    $this->artisan('data:forget-address', ['tenant' => $this->tenant->id, 'address' => 'ana@cliente.test'])
        ->expectsOutputToContain('2 emails de ana@cliente.test')
        ->assertSuccessful();
    expect(inTenant($this->tenant, fn () => Email::count()))->toBe(3);

    $this->artisan('data:forget-address', ['tenant' => $this->tenant->id, 'address' => 'ANA@cliente.test', '--force' => true])->assertSuccessful();

    expect(inTenant($this->tenant, fn () => Email::pluck('from_email')->all()))->toBe(['otro@cliente.test'])
        ->and(inTenant($other, fn () => Email::count()))->toBe(1);

    $log = inTenant($this->tenant, fn () => AuditLog::sole());
    expect($log->action)->toBe('retention.address_forgotten')
        ->and($log->metadata)->toEqual(['address_sha256' => hash('sha256', 'ana@cliente.test'), 'emails' => 2, 'orders' => 0])
        ->and(json_encode($log->getAttributes()))->not->toContain('ana@');
});

// --- API ---------------------------------------------------------------------------

it('lets owners and admins delete an email through the api, leaving no personal data in the audit', function () {
    // Email fijo: la auditoría guarda el del actor y uno aleatorio podría contener "ana@" (p. ej. juliana@...).
    $admin = User::factory()->for($this->tenant)->admin()->create(['email' => 'admin@acme.test']);
    $email = fullEmail($this->tenant, ['subject' => 'Mi DNI es 12345678Z']);

    $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/emails/{$email->id}")->assertNoContent();

    expect(inTenant($this->tenant, fn () => Email::count()))->toBe(0);
    $log = inTenant($this->tenant, fn () => AuditLog::sole());
    expect($log->action)->toBe('email.deleted')
        ->and($log->subject_label)->toBe("Email #{$email->id}")
        ->and($log->metadata['from_domain'])->toBe('cliente.test')
        ->and(json_encode($log->getAttributes()))->not->toContain('12345678Z')->not->toContain('ana@');
});

it('does not let agents or other companies delete emails, nor delete one in flight', function () {
    $email = fullEmail($this->tenant);
    $agent = User::factory()->for($this->tenant)->agent()->create();
    $stranger = User::factory()->for(Tenant::factory()->create())->owner()->create();
    $owner = User::factory()->for($this->tenant)->owner()->create();

    $this->actingAs($agent, 'sanctum')->deleteJson("/api/v1/emails/{$email->id}")->assertForbidden();
    app('auth')->forgetGuards();
    $this->actingAs($stranger, 'sanctum')->deleteJson("/api/v1/emails/{$email->id}")->assertNotFound();
    app('auth')->forgetGuards();
    inTenant($this->tenant, fn () => $email->forceFill(['status' => EmailStatus::Processing])->save());
    $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/emails/{$email->id}")->assertStatus(409);

    expect(inTenant($this->tenant, fn () => Email::count()))->toBe(1);
});

it('lets the owner choose how long the emails are kept', function () {
    $owner = User::factory()->for($this->tenant)->owner()->create();
    $admin = User::factory()->for($this->tenant)->admin()->create();

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/tenant')
        ->assertJsonPath('data.email_retention_days', null)
        ->assertJsonPath('data.retention.email_days', 365);

    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/tenant', ['email_retention_days' => 90])
        ->assertOk()
        ->assertJsonPath('data.retention.email_days', 90)
        ->assertJsonPath('data.name', 'Acme');

    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/tenant', ['email_retention_days' => 7])->assertJsonValidationErrors(['email_retention_days']);
    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/tenant', ['email_retention_days' => null])->assertJsonPath('data.retention.email_days', 365);
    app('auth')->forgetGuards();
    $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/tenant', ['email_retention_days' => 30])->assertForbidden();

    expect(inTenant($this->tenant, fn () => AuditLog::where('action', 'tenant.updated')->count()))->toBe(2);
});
