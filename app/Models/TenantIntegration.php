<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sistema propio conectado por una empresa (hoy, la API estándar). La clave
 * va cifrada (`credentials`, cast `encrypted`) y nunca sale en JSON. Nada es
 * asignable en masa: lo escribe App\ERP\Connections\IntegrationService.
 */
#[Hidden(['credentials'])]
class TenantIntegration extends Model
{
    use BelongsToTenant;

    public const string STANDARD_API = 'standard_api';

    public const string WOOCOMMERCE = 'woocommerce';

    protected $guarded = ['*'];

    protected $attributes = [
        'status' => 'pending',
        'orders' => true,
        'invoices' => false,
        'appointments' => false,
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted',
            'orders' => 'boolean',
            'invoices' => 'boolean',
            'appointments' => 'boolean',
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
