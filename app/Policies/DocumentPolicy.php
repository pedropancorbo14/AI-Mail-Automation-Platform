<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Documentación de la empresa: otra empresa → 404 (antes que el permiso,
 * para no delatar que existe); luego documents.view / documents.manage.
 */
class DocumentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::DOCUMENTS_VIEW);
    }

    public function view(User $actor, Document $document): Response
    {
        return $this->sameTenant($actor, $document)
            ?? $this->allowIf($actor->hasPermission(Permission::DOCUMENTS_VIEW));
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::DOCUMENTS_MANAGE);
    }

    /** Reindexar. */
    public function update(User $actor, Document $document): Response
    {
        return $this->sameTenant($actor, $document)
            ?? $this->allowIf($actor->hasPermission(Permission::DOCUMENTS_MANAGE));
    }

    public function delete(User $actor, Document $document): Response
    {
        return $this->update($actor, $document);
    }

    private function sameTenant(User $actor, Document $document): ?Response
    {
        return $actor->tenant_id === $document->tenant_id ? null : Response::denyAsNotFound();
    }

    private function allowIf(bool $condition): Response
    {
        return $condition ? Response::allow() : Response::deny();
    }
}
