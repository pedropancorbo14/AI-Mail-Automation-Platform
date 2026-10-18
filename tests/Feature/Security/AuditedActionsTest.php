<?php

use App\Mail\Enums\MailProviderType;
use App\Mail\MailProviderFactory;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\MailConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailProvider;

/*
 * Cada acción de una persona deja una fila en audit_logs con quién, sobre
 * qué y qué cambió, y nunca un secreto.
 */

beforeEach(function () {
    RateLimiter::clear('owner@acme.test|127.0.0.1');
    $this->acme = Tenant::factory()->create(['name' => 'Acme']);
    $this->owner = User::factory()->for($this->acme)->owner()->create(['name' => 'Olga', 'email' => 'owner@acme.test']);
});

/** @return list<AuditLog> Registros de la empresa, del más antiguo al más reciente. */
function auditOf(Tenant $tenant, ?string $action = null): array
{
    return inTenant($tenant, fn () => AuditLog::when($action, fn ($q) => $q->where('action', $action))->orderBy('id')->get()->all());
}

function asOwner(): Tests\TestCase
{
    app('auth')->forgetGuards();

    return test()->actingAs(test()->owner, 'sanctum');
}

// --- Sesiones ------------------------------------------------------------------

it('records logins with a token and logouts', function () {
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'password', 'device_name' => 'crm'])
        ->assertOk()->json('token');

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    [$login, $logout] = auditOf($this->acme);
    expect($login)
        ->action->toBe('auth.login')
        ->user_id->toBe($this->owner->id)
        ->ip->toBe('127.0.0.1')
        ->metadata->toEqual(['method' => 'token', 'device_name' => 'crm']);
    expect($logout)->action->toBe('auth.logout')->metadata->toEqual(['method' => 'token']);
    expect(json_encode([$login->getAttributes(), $logout->getAttributes()]))->not->toContain(explode('|', $token)[1]);
});

it('records panel logins with a session', function () {
    config(['sanctum.stateful' => ['panel.test']]);

    $this->withHeaders(['Origin' => 'http://panel.test', 'Referer' => 'http://panel.test/'])
        ->postJson('/api/v1/auth/session', ['email' => 'owner@acme.test', 'password' => 'password'])
        ->assertOk();

    expect(auditOf($this->acme, 'auth.login')[0]->metadata)->toEqual(['method' => 'session']);
});

it('records failed logins on an existing account in its company, without the password', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'adivinando-123'])->assertUnprocessable();

    $log = auditOf($this->acme)[0];
    expect($log)
        ->action->toBe('auth.login_failed')
        ->user_id->toBeNull()
        ->subject_id->toBe($this->owner->id)
        ->metadata->toEqual(['email' => 'owner@acme.test']);
    expect(json_encode($log->getAttributes()))->not->toContain('adivinando');
});

it('records failed logins with an unknown email without a company', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'nadie@x.test', 'password' => 'x'])->assertUnprocessable();

    expect(auditOf($this->acme))->toBe([]);
    expect(AuditLog::withoutGlobalScope(TenantScope::class)->sole())
        ->action->toBe('auth.login_failed')
        ->tenant_id->toBeNull()
        ->metadata->toEqual(['email' => 'nadie@x.test']);
});

it('records logins blocked because the company is deactivated', function () {
    $this->acme->forceFill(['is_active' => false])->save();

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'password'])->assertForbidden();

    expect(auditOf($this->acme)[0])->action->toBe('auth.login_blocked')->user_id->toBe($this->owner->id);
});

// --- Usuarios y empresa ------------------------------------------------------------

it('records creating, changing and deleting users, hiding the password', function () {
    $id = asOwner()->postJson('/api/v1/users', ['name' => 'Lucía', 'email' => 'lucia@acme.test', 'password' => 'Secreta-12345', 'role' => 'agent'])
        ->assertCreated()->json('data.id');

    asOwner()->patchJson("/api/v1/users/{$id}", ['name' => 'Lucía G.', 'role' => 'admin', 'password' => 'Otra-Secreta-678'])->assertOk();
    asOwner()->patchJson("/api/v1/users/{$id}", ['name' => 'Lucía G.'])->assertOk(); // sin cambios: sin registro
    asOwner()->deleteJson("/api/v1/users/{$id}")->assertNoContent();

    [$created, $updated, $deleted] = auditOf($this->acme);

    expect($created)->action->toBe('user.created')->subject_id->toBe($id)->subject_label->toBe('Lucía')
        ->user_id->toBe($this->owner->id)->actor_email->toBe('owner@acme.test')
        ->metadata->toEqual(['email' => 'lucia@acme.test', 'role' => 'agent']);
    expect($updated)->action->toBe('user.updated')->changes->toEqual([
        'name' => ['from' => 'Lucía', 'to' => 'Lucía G.'],
        'role' => ['from' => 'agent', 'to' => 'admin'],
        'password' => '[oculto]',
    ]);
    expect($deleted)->action->toBe('user.deleted')->subject_label->toBe('Lucía G.');

    expect(json_encode(array_map(fn ($log) => $log->getAttributes(), auditOf($this->acme))))
        ->not->toContain('Secreta')->not->toContain('$2y$');
});

it('records renaming the company', function () {
    asOwner()->patchJson('/api/v1/tenant', ['name' => 'Acme SL'])->assertOk();

    expect(auditOf($this->acme)[0])->action->toBe('tenant.updated')
        ->changes->toEqual(['name' => ['from' => 'Acme', 'to' => 'Acme SL']]);
});

// --- Buzones -----------------------------------------------------------------------

it('records the mailbox lifecycle without credentials', function () {
    app(MailProviderFactory::class)->extend(MailProviderType::Imap, fn () => new FakeMailProvider());
    $server = ['host' => '8.8.8.8', 'port' => 993, 'encryption' => 'ssl', 'username' => 'soporte@acme.test', 'password' => 'clave-imap-super'];

    $id = asOwner()->postJson('/api/v1/mail-connections', [
        'name' => 'Soporte', 'email' => 'soporte@acme.test', 'provider' => 'imap',
        'imap' => $server, 'smtp' => [...$server, 'host' => '1.1.1.1', 'port' => 587, 'encryption' => 'starttls'],
    ])->assertCreated()->json('data.id');

    asOwner()->postJson("/api/v1/mail-connections/{$id}/test")->assertOk();
    asOwner()->patchJson("/api/v1/mail-connections/{$id}", ['name' => 'Soporte web', 'imap' => [...$server, 'password' => 'clave-nueva-super']])->assertOk();
    asOwner()->patchJson("/api/v1/mail-connections/{$id}", ['enabled' => false])->assertOk();
    asOwner()->deleteJson("/api/v1/mail-connections/{$id}")->assertNoContent();

    $logs = auditOf($this->acme);
    expect(array_map(fn ($log) => $log->action, $logs))->toBe([
        'mail_connection.created', 'mail_connection.tested', 'mail_connection.updated', 'mail_connection.updated', 'mail_connection.deleted',
    ]);
    expect($logs[0]->metadata)->toEqual(['email' => 'soporte@acme.test', 'provider' => 'imap']);
    expect($logs[1]->metadata)->toEqual(['incoming_ok' => true, 'outgoing_ok' => true]);
    expect($logs[2]->changes)->toEqual([
        'name' => ['from' => 'Soporte', 'to' => 'Soporte web'],
        'status' => ['from' => 'active', 'to' => 'pending'],
        'credentials' => '[oculto]',
    ]);
    expect($logs[3]->changes)->toEqual(['status' => ['from' => 'pending', 'to' => 'disabled']]);
    expect(json_encode(array_map(fn ($log) => $log->getAttributes(), $logs)))->not->toContain('clave-');
});

// --- IA y documentación ---------------------------------------------------------------

it('records changes to the automation and to the tools', function () {
    asOwner()->patchJson('/api/v1/automation', ['mode' => 'auto', 'auto_send_intents' => ['order_status']])->assertOk();
    asOwner()->patchJson('/api/v1/automation/tools/get_order', ['enabled' => true])->assertOk();

    [$automation, $tool] = auditOf($this->acme);
    expect($automation)->action->toBe('automation.updated')->changes->toEqual([
        'automation_mode' => ['from' => 'draft', 'to' => 'auto'],
        'auto_send_intents' => ['from' => null, 'to' => ['order_status']],
    ]);
    expect($tool)->action->toBe('automation.tool_changed')->subject_label->toBe('get_order')
        ->metadata->toEqual(['tool' => 'get_order', 'enabled' => true]);
});

it('records uploading, reindexing and deleting documents', function () {
    Storage::fake('local');

    $id = asOwner()->postJson('/api/v1/documents', [
        'file' => UploadedFile::fake()->createWithContent('politica.md', "# Devoluciones\n\n30 días."),
        'title' => 'Política',
    ])->assertCreated()->json('data.id');
    asOwner()->postJson("/api/v1/documents/{$id}/reindex")->assertOk();
    asOwner()->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

    $logs = auditOf($this->acme);
    expect(array_map(fn ($log) => [$log->action, $log->subject_type, $log->subject_id, $log->subject_label], $logs))->toBe([
        ['document.uploaded', 'Document', $id, 'Política'],
        ['document.reindexed', 'Document', $id, 'Política'],
        ['document.deleted', 'Document', $id, 'Política'],
    ]);
    expect($logs[0]->metadata)->toMatchArray(['filename' => 'politica.md', 'mime_type' => 'text/markdown']);
    expect(inTenant($this->acme, fn () => Document::count()))->toBe(0);
});

it('never records anything in the audit log of another company', function () {
    $globex = Tenant::factory()->create();
    asOwner()->patchJson('/api/v1/tenant', ['name' => 'Acme SL'])->assertOk();

    expect(auditOf($globex))->toBe([])
        ->and(count(auditOf($this->acme)))->toBe(1);
});
