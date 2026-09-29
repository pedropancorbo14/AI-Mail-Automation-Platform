<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Concerns\BelongsToTenant;
use App\Tenancy\TenantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class TenantBoundDocument extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_bound_documents';

    protected $guarded = [];
}

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant'])->prefix('api/v1/_test')->group(function () {
        Route::get('/tenant', fn () => [
            'tenant_id' => app(TenantService::class)->id(),
            'context_tenant_id' => Context::get('tenant_id'),
        ]);
        Route::get('/documents/{document}', fn (TenantBoundDocument $document) => $document);
    });
});

it('sets the tenant of the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/_test/tenant')
        ->assertOk()
        ->assertExactJson(['tenant_id' => $user->tenant_id, 'context_tenant_id' => $user->tenant_id]);
});

it('ignores any tenant sent by the client', function () {
    $user = User::factory()->create();
    $other = Tenant::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->withHeader('X-Tenant', (string) $other->id)
        ->getJson("/api/v1/_test/tenant?tenant_id={$other->id}")
        ->assertJsonPath('tenant_id', $user->tenant_id);
});

it('blocks existing tokens when the tenant is deactivated', function () {
    $user = User::factory()->create();
    $token = $user->createToken('panel')->plainTextToken;

    $user->tenant->update(['is_active' => false]);

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('message', 'La empresa está desactivada.');
});

it('resolves the tenant before route model binding', function () {
    Schema::create('tenant_bound_documents', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('title');
        $table->timestamps();
    });

    $acmeUser = User::factory()->create();
    $globexUser = User::factory()->create();
    $tenancy = app(TenantService::class);

    $own = $tenancy->runAs($acmeUser->tenant, fn () => TenantBoundDocument::create(['title' => 'propio']));
    $foreign = $tenancy->runAs($globexUser->tenant, fn () => TenantBoundDocument::create(['title' => 'ajeno']));

    $this->actingAs($acmeUser, 'sanctum')
        ->getJson("/api/v1/_test/documents/{$own->id}")
        ->assertOk()
        ->assertJsonPath('title', 'propio');

    $this->actingAs($acmeUser, 'sanctum')
        ->getJson("/api/v1/_test/documents/{$foreign->id}")
        ->assertNotFound();
});
