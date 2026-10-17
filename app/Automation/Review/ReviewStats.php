<?php

namespace App\Automation\Review;

use App\Models\Email;
use App\Models\EmailReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resumen de la revisión humana de un periodo (dentro del tenant), a partir
 * de email_reviews: cuántas decisiones de cada tipo, cuánto esperan los
 * casos, qué se hace con las propuestas de la IA (aprobadas tal cual,
 * corregidas o descartadas: la mejor medida de su calidad real) y lo que
 * decidió cada persona.
 */
class ReviewStats
{
    /** Decisiones que resuelven el caso (descartar una propuesta no lo resuelve). */
    private const array RESOLVING = [ReviewDecision::Approved, ReviewDecision::Replied, ReviewDecision::Closed, ReviewDecision::Reprocessed];

    /** @return array<string, mixed> */
    public function summary(int $days): array
    {
        $since = now()->subDays($days);
        $reviews = fn () => EmailReview::query()->where('email_reviews.created_at', '>=', $since);

        $byDecision = $reviews()->selectRaw('decision, count(*) as total')->groupBy('decision')->pluck('total', 'decision');
        $count = fn (ReviewDecision $decision) => (int) ($byDecision[$decision->value] ?? 0);

        $approvedEdited = $reviews()->where('decision', ReviewDecision::Approved)->where('edited', true)->count();
        $approved = $count(ReviewDecision::Approved);
        $rejected = $count(ReviewDecision::Rejected);
        $proposals = $approved + $rejected;

        $wait = $reviews()
            ->whereIn('decision', self::RESOLVING)
            ->whereNotNull('waited_seconds')
            ->selectRaw('avg(waited_seconds) as average, percentile_cont(0.5) within group (order by waited_seconds) as median')
            ->first();

        return [
            'days' => $days,
            'pending' => Email::query()->whereIn('status', HumanReviewService::REVIEWABLE)->count(),
            'decisions' => collect(ReviewDecision::cases())->mapWithKeys(fn (ReviewDecision $d) => [$d->value => $count($d)])->all(),
            'resolved' => collect(self::RESOLVING)->sum($count),
            'wait_seconds' => [
                'average' => $wait?->average === null ? null : (int) round((float) $wait->average),
                'median' => $wait?->median === null ? null : (int) round((float) $wait->median),
            ],
            // De las propuestas de la IA que revisó una persona.
            'ai_proposals' => [
                'reviewed' => $proposals,
                'approved_unchanged' => $approved - $approvedEdited,
                'approved_edited' => $approvedEdited,
                'rejected' => $rejected,
                'unchanged_rate' => $proposals === 0 ? null : round(($approved - $approvedEdited) / $proposals, 3),
            ],
            'people' => $this->people($since),
        ];
    }

    /** @return list<array<string, mixed>> Lo que decidió cada persona, la más activa primero. */
    private function people(\DateTimeInterface $since): array
    {
        $rows = EmailReview::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->select('user_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where edited) as edited')
            ->selectRaw('avg(waited_seconds) filter (where decision <> ?) as average_wait', [ReviewDecision::Rejected->value])
            ->tap(function ($query) {
                foreach (ReviewDecision::cases() as $decision) {
                    $query->selectRaw('count(*) filter (where decision = ?) as ' . $decision->value, [$decision->value]);
                }
            })
            ->groupBy('user_id')
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        $names = User::query()->whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'user' => ['id' => $row->user_id, 'name' => $names[$row->user_id] ?? null],
            'total' => (int) $row->total,
            'decisions' => collect(ReviewDecision::cases())->mapWithKeys(fn (ReviewDecision $d) => [$d->value => (int) $row->{$d->value}])->all(),
            'edited' => (int) $row->edited,
            'average_wait_seconds' => $row->average_wait === null ? null : (int) round((float) $row->average_wait),
        ])->all();
    }
}
