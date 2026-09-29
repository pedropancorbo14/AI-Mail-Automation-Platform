<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Orden de las comprobaciones: primero el tenant (un usuario de otra
 * empresa es un 404, no revela que existe), después el permiso y por último
 * las reglas sobre owners y sobre uno mismo.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::USERS_VIEW);
    }

    public function view(User $actor, User $user): Response
    {
        return $this->sameTenant($actor, $user)
            ?? $this->allowIf($actor->hasPermission(Permission::USERS_VIEW));
    }

    public function create(User $actor, ?string $role = null): Response
    {
        if (! $actor->hasPermission(Permission::USERS_CREATE)) {
            return Response::deny();
        }

        return $this->canAssignRole($actor, $role);
    }

    public function update(User $actor, User $user, ?string $role = null): Response
    {
        if ($denied = $this->sameTenant($actor, $user)) {
            return $denied;
        }

        if (! $actor->hasPermission(Permission::USERS_UPDATE) || $this->touchesOwner($actor, $user)) {
            return Response::deny();
        }

        if ($role !== null && $actor->is($user) && $role !== $user->role->name) {
            return Response::deny('No puedes cambiar tu propio rol.');
        }

        return $this->canAssignRole($actor, $role);
    }

    public function delete(User $actor, User $user): Response
    {
        if ($denied = $this->sameTenant($actor, $user)) {
            return $denied;
        }

        if ($actor->is($user)) {
            return Response::deny('No puedes eliminar tu propio usuario.');
        }

        return $this->allowIf(
            $actor->hasPermission(Permission::USERS_DELETE) && ! $this->touchesOwner($actor, $user),
        );
    }

    private function sameTenant(User $actor, User $user): ?Response
    {
        return $actor->tenant_id === $user->tenant_id ? null : Response::denyAsNotFound();
    }

    /** Solo un owner puede modificar o eliminar a otro owner. */
    private function touchesOwner(User $actor, User $user): bool
    {
        return $user->hasRole(Role::OWNER) && ! $actor->hasRole(Role::OWNER);
    }

    /** Solo un owner puede dar el rol owner. */
    private function canAssignRole(User $actor, ?string $role): Response
    {
        return $this->allowIf($role !== Role::OWNER || $actor->hasRole(Role::OWNER));
    }

    private function allowIf(bool $condition): Response
    {
        return $condition ? Response::allow() : Response::deny();
    }
}
