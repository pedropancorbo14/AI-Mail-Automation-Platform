<?php

namespace App\Console\Commands;

use App\Models\AiShadowRun;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Informe del modo sombra para el operador: en qué coinciden ai-service y el
 * motor de Laravel con los mismos emails, cuánto cuesta y tarda cada uno, y
 * los casos en los que no coinciden (para revisarlos). Costes: solo el
 * operador (como Grafana), nunca en el panel de las empresas.
 */
class ShadowReport extends Command
{
    protected $signature = 'ai:shadow-report {--days=7 : Días hacia atrás} {--tenant= : Solo esta empresa (id)} {--limit=10 : Desacuerdos a listar}';

    protected $description = 'Compara ai-service (modo sombra) con el motor de Laravel';

    private const array DECISIONS = ['auto_send', 'human_review', 'ignore', 'failed'];

    public function handle(): int
    {
        $runs = fn (): Builder => AiShadowRun::withoutGlobalScope(TenantScope::class)
            ->whereNotNull('compared_at')
            ->where('compared_at', '>=', now()->subDays((int) $this->option('days')))
            ->when($this->option('tenant'), fn (Builder $q, $tenant) => $q->where('tenant_id', (int) $tenant));

        $total = $runs()->count();
        $pending = AiShadowRun::withoutGlobalScope(TenantScope::class)->whereNull('compared_at')->count();

        if ($total === 0) {
            $this->warn("No hay copias comparadas en el periodo (pendientes: {$pending}).");

            return self::SUCCESS;
        }

        $pct = fn (int $n, int $of) => $of > 0 ? sprintf('%.1f %%', 100 * $n / $of) : '—';
        $withIntent = $runs()->whereNotNull('intent_match')->count();

        $this->info("Copias comparadas: {$total} (pendientes de comparar: {$pending})");
        $this->table(['Medida', 'Laravel', 'ai-service (Python)'], [
            ['Misma intención', $pct($runs()->where('intent_match', true)->count(), $withIntent), ''],
            ['Misma decisión', $pct($runs()->where('decision_match', true)->count(), $total), ''],
            ['Coste medio por email', sprintf('%.4f $', (float) $runs()->avg('laravel_cost_usd')), sprintf('%.4f $', (float) $runs()->avg('cost_usd'))],
            ['Tiempo medio hasta decidir', self::seconds($runs()->avg('laravel_duration_ms')), self::seconds($runs()->avg('duration_ms'))],
            ['Fallos', (string) $runs()->where('laravel_decision', 'failed')->count(), (string) $runs()->where('python_decision', 'failed')->count()],
        ]);

        $this->line('Decisiones (filas: Laravel · columnas: ai-service)');
        $this->table(['Laravel \\ Python', ...self::DECISIONS], array_map(
            fn (string $laravel) => [$laravel, ...array_map(
                fn (string $python) => (string) $runs()->where('laravel_decision', $laravel)->where('python_decision', $python)->count(),
                self::DECISIONS,
            )],
            self::DECISIONS,
        ));

        $disagreements = $runs()->where('decision_match', false)->latest('compared_at')->limit((int) $this->option('limit'))->get();
        if ($disagreements->isNotEmpty()) {
            $this->line('Últimos desacuerdos de decisión');
            $this->table(['Email', 'Intención Laravel → Python', 'Decisión Laravel → Python', 'Motivo de ai-service'], $disagreements->map(fn (AiShadowRun $run) => [
                "#{$run->email_id}",
                ($run->laravel_intent ?? '—') . ' → ' . ($run->category ?? '—'),
                "{$run->laravel_decision} → {$run->python_decision}",
                mb_strimwidth(implode(' ', $run->reasons ?? []) ?: ($run->error ?? ''), 0, 80, '…'),
            ])->all());
        }

        return self::SUCCESS;
    }

    private static function seconds(mixed $ms): string
    {
        return $ms === null ? '—' : sprintf('%.1f s', (float) $ms / 1000);
    }
}
