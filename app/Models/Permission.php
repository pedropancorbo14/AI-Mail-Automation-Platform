<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'label'])]
class Permission extends Model
{
    public const string USERS_VIEW = 'users.view';

    public const string USERS_CREATE = 'users.create';

    public const string USERS_UPDATE = 'users.update';

    public const string USERS_DELETE = 'users.delete';

    public const string TENANT_VIEW = 'tenant.view';

    public const string TENANT_UPDATE = 'tenant.update';

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
