<?php

use App\Models\User;

it('shows the company of the user', function () {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent, 'sanctum')
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.id', $agent->tenant_id);
});

it('lets only the owner rename the company', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->for($owner->tenant)->admin()->create();

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/v1/tenant', ['name' => 'Nuevo nombre'])
        ->assertForbidden();

    $this->actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/tenant', ['name' => 'Nuevo nombre'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nuevo nombre');
});

it('does not let the company change its slug or reactivate itself', function () {
    $owner = User::factory()->owner()->create();
    $slug = $owner->tenant->slug;

    $this->actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/tenant', ['name' => 'Otro', 'slug' => 'robado', 'is_active' => false])
        ->assertOk();

    expect($owner->tenant->fresh())
        ->slug->toBe($slug)
        ->is_active->toBeTrue();
});
