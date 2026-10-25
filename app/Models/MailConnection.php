<?php

namespace App\Models;

use App\Mail\Credentials\EncryptedCredentials;
use App\Mail\Credentials\MailCredentials;
use App\Mail\Enums\MailConnectionStatus;
use App\Mail\Enums\MailProviderType;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\MailConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Cuenta de correo conectada por una empresa. Filtrada siempre por el
 * tenant actual (BelongsToTenant). provider y credentials se asignan de
 * forma explícita, nunca por asignación masiva desde la petición.
 *
 * @property MailProviderType $provider
 * @property MailConnectionStatus $status
 * @property MailCredentials $credentials
 */
#[Fillable(['name', 'email'])]
#[Hidden(['credentials'])]
class MailConnection extends Model
{
    /** @use HasFactory<MailConnectionFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'provider' => MailProviderType::class,
            'status' => MailConnectionStatus::class,
            'credentials' => EncryptedCredentials::class,
            'last_tested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Las credenciales tienen que corresponder al proveedor.
        static::saving(function (MailConnection $connection) {
            $expected = $connection->provider->credentialsClass();

            if (! $connection->credentials instanceof $expected) {
                throw new InvalidArgumentException(
                    "Las conexiones {$connection->provider->value} necesitan credenciales " . class_basename($expected) . '.',
                );
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Email, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /** @return HasMany<MailSyncState, $this> */
    public function syncStates(): HasMany
    {
        return $this->hasMany(MailSyncState::class);
    }

    /** @return HasMany<MailSkippedMessage, $this> */
    public function skippedMessages(): HasMany
    {
        return $this->hasMany(MailSkippedMessage::class);
    }
}
