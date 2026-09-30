<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Sigue aplicando BelongsToTenant: solo funciona con este tenant (u otro)
     * en contexto, y siempre filtra por el tenant del contexto.
     *
     * @return HasMany<MailConnection, $this>
     */
    public function mailConnections(): HasMany
    {
        return $this->hasMany(MailConnection::class);
    }
}
