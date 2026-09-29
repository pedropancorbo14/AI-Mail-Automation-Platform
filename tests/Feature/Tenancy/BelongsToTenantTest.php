<?php

use App\Models\Tenant;
use App\Tenancy\Concerns\BelongsToTenant;
use App\Tenancy\Exceptions\MissingTenantException;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Modelo de prueba: todavía no existe ningún modelo real con tenant_id
// aparte de User (llegarán en Fase 3+).
class TenantScopedNote extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_scoped_notes';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('tenant_scoped_notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('body');
        $table->timestamps();
    });

    [$this->acme, $this->globex] = Tenant::factory()->count(2)->create();
    $this->tenancy = app(TenantService::class);
});

it('assigns the current tenant when creating', function () {
    $this->tenancy->set($this->acme);

    expect(TenantScopedNote::create(['body' => 'hola'])->tenant_id)->toBe($this->acme->id);
});

it('only returns rows of the current tenant', function () {
    $this->tenancy->runAs($this->acme, fn () => TenantScopedNote::create(['body' => 'de acme']));
    $this->tenancy->runAs($this->globex, fn () => TenantScopedNote::create(['body' => 'de globex']));

    $this->tenancy->set($this->acme);

    expect(TenantScopedNote::pluck('body')->all())->toBe(['de acme'])
        ->and(TenantScopedNote::count())->toBe(1);
});

it('cannot find a row of another tenant by id', function () {
    $note = $this->tenancy->runAs($this->globex, fn () => TenantScopedNote::create(['body' => 'secreto']));

    $this->tenancy->set($this->acme);

    expect(TenantScopedNote::find($note->id))->toBeNull();
});

it('refuses to query without a tenant in context', function () {
    TenantScopedNote::count();
})->throws(MissingTenantException::class);

it('refuses to create without a tenant in context', function () {
    TenantScopedNote::create(['body' => 'huérfana']);
})->throws(MissingTenantException::class);

it('refuses to create rows for another tenant', function () {
    $this->tenancy->set($this->acme);

    TenantScopedNote::create(['body' => 'intruso', 'tenant_id' => $this->globex->id]);
})->throws(MissingTenantException::class);

it('allows deliberate global queries without the scope', function () {
    $this->tenancy->runAs($this->acme, fn () => TenantScopedNote::create(['body' => 'a']));
    $this->tenancy->runAs($this->globex, fn () => TenantScopedNote::create(['body' => 'b']));

    expect(TenantScopedNote::withoutGlobalScope(TenantScope::class)->count())->toBe(2);
});
