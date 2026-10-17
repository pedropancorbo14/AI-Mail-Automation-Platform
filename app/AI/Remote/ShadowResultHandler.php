<?php

namespace App\AI\Remote;

use App\AI\CostCalculator;
use App\AI\Data\LLMUsage;
use App\AI\Remote\Exceptions\TenantMismatchException;
use App\Models\AiShadowRun;
use App\Models\Tenant;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Support\Facades\Log;

/**
 * Guarda el resultado de una copia en modo sombra (AI-Service, Fase 5) en su
 * fila de ai_shadow_runs. NUNCA toca el email: ni estado, ni respuestas, ni
 * ai_requests (las peticiones de la copia no son del email real; su coste se
 * guarda aquí y cuenta para el presupuesto diario).
 *
 * Mismas garantías que los resultados reales: la copia tiene que existir y ser
 * de la empresa y el email del evento (si no, cola de errores), y una entrega
 * repetida no cambia nada.
 */
class ShadowResultHandler
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly CostCalculator $costs,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event  Ya validado contra el contrato.
     * @return string missing, stale o shadow (guardado).
     */
    public function handle(array $event, string $type): string
    {
        $causation = $event['causation_id'] ?? null;
        $run = $causation === null ? null : AiShadowRun::withoutGlobalScope(TenantScope::class)
            ->where('event_id', $causation)
            ->first();

        if ($run === null) {
            Log::notice('Resultado en modo sombra sin copia pendiente', ['event_id' => $event['event_id']]);

            return 'missing';
        }

        if ($run->tenant_id !== $event['tenant_id'] || $run->email_id !== $event['email_id']) {
            Log::critical('Resultado en modo sombra con otra empresa u otro email: descartado', [
                'shadow_run_id' => $run->id,
                'event_id' => $event['event_id'],
            ]);

            throw new TenantMismatchException('La empresa o el email del evento no son los de la copia en sombra.');
        }

        if ($run->status !== AiShadowRun::PENDING) {
            return 'stale';
        }

        $calls = $event['usage']['calls'] ?? [];
        $values = [
            'completed_at' => now(),
            'input_tokens' => array_sum(array_column($calls, 'input_tokens')),
            'output_tokens' => array_sum(array_column($calls, 'output_tokens')),
            'cost_usd' => $this->cost($calls),
        ];

        if ($type === RemoteResultHandler::PROCESSED) {
            $values += [
                'status' => AiShadowRun::COMPLETED,
                'category' => $event['category'],
                'confidence' => $event['classification']['confidence'],
                'reply_confidence' => $event['draft'] !== '' ? $event['confidence'] : null,
                'decision' => $event['decision'],
                'draft' => $event['draft'] !== '' ? $event['draft'] : null,
                'reasons' => $event['reasons'],
                'specialists' => $event['specialists'] ?? [],
                'tool_calls' => array_map(fn (array $call) => [
                    'tool' => $call['tool'],
                    'status' => $call['status'],
                    'origin' => $call['origin'] ?? 'plan',
                ], $event['tool_calls'] ?? []),
                'model' => $event['model'],
                'duration_ms' => $event['duration_ms'] ?? null,
            ];
        } else {
            $values += [
                'status' => AiShadowRun::FAILED,
                'error_code' => $event['error_code'],
                'error' => mb_substr($event['message'], 0, 500),
            ];
        }

        $tenant = Tenant::findOrFail($run->tenant_id);
        $this->tenants->runAs($tenant, fn () => $run->forceFill($values)->save());

        return 'shadow';
    }

    /**
     * Coste con los precios de Laravel (como las peticiones reales). Null si
     * ningún modelo tiene precio.
     *
     * @param  list<array<string, mixed>>  $calls
     */
    private function cost(array $calls): ?string
    {
        $costs = array_filter(array_map(
            fn (array $c) => $this->costs->forUsage($c['model'], new LLMUsage(
                $c['input_tokens'],
                $c['output_tokens'],
                $c['cache_write_tokens'] ?? 0,
                $c['cache_read_tokens'] ?? 0,
            )),
            $calls,
        ), fn ($cost) => $cost !== null);

        return $costs === [] ? null : sprintf('%.6f', array_sum(array_map('floatval', $costs)));
    }
}
