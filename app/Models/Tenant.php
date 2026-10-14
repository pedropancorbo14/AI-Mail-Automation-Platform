<?php

namespace App\Models;

use App\AI\Classification\EmailIntent;
use App\Automation\AutomationMode;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property AutomationMode $automation_mode
 */
#[Fillable(['name', 'slug', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $attributes = [
        'automation_mode' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'automation_mode' => AutomationMode::class,
            'auto_send_intents' => 'array',
        ];
    }

    /**
     * Intenciones a las que la IA puede responder sola en modo Auto: las
     * elegidas por la empresa o, si no eligió, las de la configuración.
     *
     * @return list<EmailIntent>
     */
    public function autoSendIntents(): array
    {
        $values = $this->auto_send_intents ?? config('ai.automation.default_auto_send_intents');

        return array_values(array_filter(
            array_map(fn (string $value) => EmailIntent::tryFrom($value), $values),
            fn (?EmailIntent $intent) => $intent?->allowsAutoSend() ?? false,
        ));
    }

    /**
     * Cambia el modo de automatización y, si se indican, las intenciones de
     * envío automático ([] = volver a las de la configuración).
     *
     * @param  list<string>|null  $intents
     *
     * @throws InvalidArgumentException intención desconocida o que nunca se responde sola
     */
    public function configureAutomation(AutomationMode $mode, ?array $intents = null): void
    {
        foreach ($intents ?? [] as $value) {
            $intent = EmailIntent::tryFrom($value)
                ?? throw new InvalidArgumentException("La intención «{$value}» no existe.");

            if (! $intent->allowsAutoSend()) {
                throw new InvalidArgumentException("La IA nunca responde sola a «{$value}»: siempre lo revisa una persona.");
            }
        }

        $this->automation_mode = $mode;

        if ($intents !== null) {
            $this->auto_send_intents = $intents === [] ? null : array_values(array_unique($intents));
        }

        $this->save();
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
