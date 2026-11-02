<?php

namespace App\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contadores de uso del mes de cada empresa (tabla tenant_usage). Suma con
 * un único INSERT ... ON CONFLICT: dos workers a la vez no pierden ninguno.
 */
class UsageMeter
{
    public const string EMAILS_RECEIVED = 'emails_received';

    public const string AI_EMAILS = 'ai_emails';

    public function increment(int $tenantId, string $counter, int $by = 1): void
    {
        if (! in_array($counter, [self::EMAILS_RECEIVED, self::AI_EMAILS], true)) {
            throw new \InvalidArgumentException("Contador de uso desconocido: {$counter}");
        }

        $now = now();

        DB::table('tenant_usage')->upsert(
            [[
                'tenant_id' => $tenantId,
                'period' => self::period()->toDateString(),
                $counter => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['tenant_id', 'period'],
            [$counter => DB::raw("tenant_usage.{$counter} + excluded.{$counter}"), 'updated_at' => $now],
        );
    }

    public function get(int $tenantId, string $counter, ?Carbon $period = null): int
    {
        return (int) DB::table('tenant_usage')
            ->where('tenant_id', $tenantId)
            ->where('period', ($period ?? self::period())->toDateString())
            ->value($counter);
    }

    /** Primer día del mes en curso (zona horaria de la app). */
    public static function period(): Carbon
    {
        return now()->startOfMonth();
    }
}
