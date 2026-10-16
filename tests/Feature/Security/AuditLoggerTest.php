<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Audit\AuditLogger;
use App\Tenancy\Exceptions\MissingTenantException;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->acme = Tenant::factory()->create(['name' => 'Acme']);
    $this->owner = User::factory()->for($this->acme)->owner()->create(['email' => 'owner@acme.test']);
    $this->logger = app(AuditLogger::class);
});

it('records who did what, on what and in which company', function () {
    $target = User::factory()->for($this->acme)->agent()->create(['name' => 'Lucía']);

    $log = $this->logger->record('user.updated', $target, 'Lucía', ['name' => ['from' => 'Lu', 'to' => 'Lucía']], ['via' => 'panel'], $this->owner);

    expect($log->fresh())
        ->tenant_id->toBe($this->acme->id)
        ->user_id->toBe($this->owner->id)
        ->actor_email->toBe('owner@acme.test')
        ->action->toBe('user.updated')
        ->subject_type->toBe('User')
        ->subject_id->toBe($target->id)
        ->subject_label->toBe('Lucía')
        ->changes->toEqual(['name' => ['from' => 'Lu', 'to' => 'Lucía']])
        ->metadata->toEqual(['via' => 'panel'])
        ->created_at->not->toBeNull();
});

it('uses the authenticated user and the request data when called from an HTTP request', function () {
    Route::get('/api/__audit-test', fn () => ['id' => app(AuditLogger::class)->record('tenant.viewed')->id])
        ->middleware(['api', 'auth:sanctum']);

    $id = $this->actingAs($this->owner)
        ->withHeaders(['User-Agent' => 'Navegador de prueba', 'X-Request-Id' => 'req-12345678'])
        ->getJson('/api/__audit-test')
        ->assertOk()
        ->json('id');

    $log = AuditLog::withoutGlobalScope(TenantScope::class)->find($id);

    expect($log)
        ->user_id->toBe($this->owner->id)
        ->tenant_id->toBe($this->acme->id)
        ->ip->toBe('127.0.0.1')
        ->user_agent->toBe('Navegador de prueba')
        ->request_id->toBe('req-12345678');
});

it('takes the company from the tenant context when there is no user (commands, jobs)', function () {
    Context::add('request_id', 'job-abcdefgh');

    $log = inTenant($this->acme, fn () => $this->logger->record('retention.purged', metadata: ['emails' => 3]));

    expect($log->fresh())
        ->tenant_id->toBe($this->acme->id)
        ->user_id->toBeNull()
        ->ip->toBeNull()
        ->user_agent->toBeNull()
        ->request_id->toBe('job-abcdefgh');
});

it('can record an action without a company (failed login with an unknown email)', function () {
    $log = $this->logger->record('auth.login_failed', metadata: ['email' => 'nadie@x.test']);

    expect(AuditLog::withoutGlobalScope(TenantScope::class)->find($log->id))
        ->tenant_id->toBeNull()
        ->metadata->toBe(['email' => 'nadie@x.test']);
});

it('never stores secrets, also inside nested data', function () {
    $log = $this->logger->record('mail_connection.updated', changes: [
        'password' => ['from' => 'vieja', 'to' => 'nueva'],
        'name' => ['from' => 'A', 'to' => 'B'],
    ], metadata: [
        'credentials' => ['imap' => ['password' => 'x']],
        'settings' => ['smtp_password' => 'x', 'host' => 'mail.acme.test', 'access_token' => 'x', 'client_secret' => 'x'],
        'Authorization' => 'Bearer x',
    ], actor: $this->owner);

    $row = DB::table('audit_logs')->find($log->id);

    expect($row->changes . $row->metadata)->not->toContain('vieja')->not->toContain('nueva')->not->toContain('"x"');
    expect($log->fresh()->metadata)->toEqual([
        'credentials' => AuditLogger::HIDDEN,
        'settings' => ['smtp_password' => AuditLogger::HIDDEN, 'host' => 'mail.acme.test', 'access_token' => AuditLogger::HIDDEN, 'client_secret' => AuditLogger::HIDDEN],
        'Authorization' => AuditLogger::HIDDEN,
    ]);
    expect($log->fresh()->changes['name'])->toEqual(['from' => 'A', 'to' => 'B']);
});

it('builds the changes of a saved model, only for the given fields, with enums and dates as text', function () {
    $this->acme->forceFill(['name' => 'Acme SL', 'automation_mode' => 'auto'])->save();

    $changes = AuditLogger::changesOf($this->acme, ['name', 'automation_mode']);

    expect($changes)->toEqual([
        'name' => ['from' => 'Acme', 'to' => 'Acme SL'],
        'automation_mode' => ['from' => 'draft', 'to' => 'auto'],
    ]);
    expect(AuditLogger::changesOf($this->acme, ['slug']))->toBe([]);
});

it('cannot be modified or deleted from the application', function () {
    $log = $this->logger->record('user.created', actor: $this->owner);

    inTenant($this->acme, function () use ($log) {
        $loaded = AuditLog::find($log->id);

        expect(fn () => $loaded->forceFill(['action' => 'otra'])->save())->toThrow(LogicException::class);
        expect(fn () => $loaded->delete())->toThrow(LogicException::class);
    });

    expect(AuditLog::withoutGlobalScope(TenantScope::class)->find($log->id)->action)->toBe('user.created');
});

it('cannot be modified even with a direct query (database trigger)', function () {
    $log = $this->logger->record('user.created', actor: $this->owner);

    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'otra']))
        ->toThrow(QueryException::class, 'solo escritura');
});

it('only shows the logs of the company in context and fails closed without one', function () {
    $globex = Tenant::factory()->create();
    $globexOwner = User::factory()->for($globex)->owner()->create();

    $this->logger->record('user.created', actor: $this->owner);
    $this->logger->record('user.created', actor: $globexOwner);
    $this->logger->record('auth.login_failed');

    expect(inTenant($this->acme, fn () => AuditLog::pluck('tenant_id')->all()))->toBe([$this->acme->id]);
    expect(fn () => AuditLog::count())->toThrow(MissingTenantException::class);
});
