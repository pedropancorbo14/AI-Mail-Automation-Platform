<?php

namespace App\Policies;

use App\Models\Email;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Emails de la empresa: otra empresa → 404 (antes que el permiso, para no
 * delatar que existe); luego emails.view (ver), emails.review (revisión
 * humana) o emails.delete (borrar).
 */
class EmailPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::EMAILS_VIEW);
    }

    public function view(User $actor, Email $email): Response
    {
        if ($actor->tenant_id !== $email->tenant_id) {
            return Response::denyAsNotFound();
        }

        return $actor->hasPermission(Permission::EMAILS_VIEW) ? Response::allow() : Response::deny();
    }

    /** Decidir sobre un email en revisión humana (aprobar, responder, cerrar...). */
    public function review(User $actor, Email $email): Response
    {
        if ($actor->tenant_id !== $email->tenant_id) {
            return Response::denyAsNotFound();
        }

        return $actor->hasPermission(Permission::EMAILS_REVIEW) ? Response::allow() : Response::deny();
    }

    public function delete(User $actor, Email $email): Response
    {
        if ($actor->tenant_id !== $email->tenant_id) {
            return Response::denyAsNotFound();
        }

        return $actor->hasPermission(Permission::EMAILS_DELETE) ? Response::allow() : Response::deny();
    }
}
