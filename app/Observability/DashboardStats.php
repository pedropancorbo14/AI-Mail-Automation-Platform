<?php

namespace App\Observability;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Mail\Enums\EmailStatus;
use App\Models\AiRequest;
use App\Models\Email;
use App\Models\EmailReply;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cifras del dashboard del panel para la empresa en contexto (todas las
 * consultas pasan por el scope de tenant). Las series temporales y las
 * alertas son de Grafana (Fase 11): aquí solo el resumen del periodo.
 */
class DashboardStats
{
    /** @return array<string, mixed> */
    public function summary(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();
        $byStatus = Email::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $received = $byStatus->sum();
        $ignored = $byStatus->get(EmailStatus::Ignored->value, 0);
        $automated = EmailReply::query()
            ->where('status', EmailReplyStatus::Sent)
            ->where('sent_automatically', true)
            ->where('sent_at', '>=', $since)
            ->count();
        // Los que necesitaban respuesta: todos menos los ignorados (spam, automáticos).
        $answerable = $received - $ignored;

        return [
            'period' => ['days' => $days, 'from' => $since->toIso8601String()],
            'emails' => [
                'received' => $received,
                'by_status' => collect(EmailStatus::cases())->mapWithKeys(fn (EmailStatus $status) => [$status->value => $byStatus->get($status->value, 0)]),
                'sent_automatically' => $automated,
                'automation_rate' => $answerable > 0 ? round($automated / $answerable, 3) : null,
                // Lo que espera a una persona ahora mismo (de cualquier fecha).
                'awaiting_human' => Email::where('status', EmailStatus::HumanReview)->count(),
                'failed' => Email::where('status', EmailStatus::Failed)->count(),
            ],
            'intents' => $this->intents($since),
            'ai' => $this->ai($since),
        ];
    }

    /** @return list<array{intent: string, count: int}> */
    private function intents(Carbon $since): array
    {
        return Email::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('intent')
            ->selectRaw('intent, count(*) as total')
            ->groupBy('intent')
            ->orderByDesc('total')
            ->orderBy('intent')
            ->get()
            ->map(fn ($row) => ['intent' => $row->intent->value, 'count' => (int) $row->total])
            ->all();
    }

    /** @return array<string, mixed> */
    private function ai(Carbon $since): array
    {
        $period = AiRequest::query()->where('created_at', '>=', $since);
        $cost = fn ($query) => round((float) $query->sum('cost_usd'), 6);
        $emailsWithAi = (clone $period)->whereNotNull('email_id')->distinct()->count('email_id');
        $periodCost = $cost(clone $period);

        return [
            'requests' => (clone $period)->count(),
            'failed_requests' => (clone $period)->where('status', '!=', 'success')->count(),
            'cost_usd' => $periodCost,
            'cost_today_usd' => $cost(AiRequest::query()->where('created_at', '>=', now()->startOfDay())),
            'cost_month_usd' => $cost(AiRequest::query()->where('created_at', '>=', now()->startOfMonth())),
            'avg_cost_per_email_usd' => $emailsWithAi > 0 ? round($periodCost / $emailsWithAi, 6) : null,
            'avg_latency_ms' => ($latency = (clone $period)->where('purpose', '!=', 'knowledge_search')->avg('latency_ms')) === null ? null : (int) round((float) $latency),
        ];
    }

    /**
     * Últimos emails que necesitan a alguien: revisión humana o error.
     *
     * @return Collection<int, Email>
     */
    public function needsAttention(int $limit = 5): Collection
    {
        return Email::query()
            ->with('mailConnection:id,name,email')
            ->whereIn('status', [EmailStatus::HumanReview, EmailStatus::Failed])
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
