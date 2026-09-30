<?php

namespace App\Policies;

use App\Models\MailConnection;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * BelongsToTenant ya impide cargar conexiones de otra empresa (el route
 * binding da 404); la comprobación de tenant se repite aquí como segunda
 * barrera por si alguna vez se carga una conexión sin el scope.
 */
class MailConnectionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::MAIL_CONNECTIONS_VIEW);
    }

    public function view(User $actor, MailConnection $connection): Response
    {
        return $this->sameTenant($actor, $connection)
            ?? $this->allowIf($actor->hasPermission(Permission::MAIL_CONNECTIONS_VIEW));
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::MAIL_CONNECTIONS_MANAGE);
    }

    public function update(User $actor, MailConnection $connection): Response
    {
        return $this->sameTenant($actor, $connection)
            ?? $this->allowIf($actor->hasPermission(Permission::MAIL_CONNECTIONS_MANAGE));
    }

    public function delete(User $actor, MailConnection $connection): Response
    {
        return $this->update($actor, $connection);
    }

    private function sameTenant(User $actor, MailConnection $connection): ?Response
    {
        return $actor->tenant_id === $connection->tenant_id ? null : Response::denyAsNotFound();
    }

    private function allowIf(bool $condition): Response
    {
        return $condition ? Response::allow() : Response::deny();
    }
}
