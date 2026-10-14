<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Herramienta de la IA activada o desactivada para una empresa. */
#[Fillable(['tool', 'enabled'])]
class TenantTool extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
