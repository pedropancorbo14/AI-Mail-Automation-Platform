<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TenantPolicy
{
    public function view(User $actor, Tenant $tenant): Response
    {
        return $this->ownTenant($actor, $tenant)
            ?? $this->allowIf($actor->hasPermission(Permission::TENANT_VIEW));
    }

    public function update(User $actor, Tenant $tenant): Response
    {
        return $this->ownTenant($actor, $tenant)
            ?? $this->allowIf($actor->hasPermission(Permission::TENANT_UPDATE));
    }

    private function ownTenant(User $actor, Tenant $tenant): ?Response
    {
        return $actor->tenant_id === $tenant->id ? null : Response::denyAsNotFound();
    }

    private function allowIf(bool $condition): Response
    {
        return $condition ? Response::allow() : Response::deny();
    }
}
