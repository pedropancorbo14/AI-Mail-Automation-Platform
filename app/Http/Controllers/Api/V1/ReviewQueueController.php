<?php

namespace App\Http\Controllers\Api\V1;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Automation\Review\HumanReviewService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\ListReviewQueueRequest;
use App\Http\Resources\ReviewQueueItemResource;
use App\Models\Email;
use App\Models\EmailReply;
use App\Models\EmailStatusChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Bandeja de revisión (Fase 13): los emails que esperan a una persona
 * (revisión humana o error), primero los urgentes y, dentro de cada
 * urgencia, los que más llevan esperando.
 */
class ReviewQueueController extends Controller
{
    public function __invoke(ListReviewQueueRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Email::class);

        $userId = $request->user()->id;
        $pending = fn (): Builder => Email::query()->whereIn('status', HumanReviewService::REVIEWABLE);

        $lastChange = fn (string $column) => EmailStatusChange::query()
            ->select($column)
            ->whereColumn('email_id', 'emails.id')
            ->latest('id')
            ->limit(1);

        $query = $pending()
            ->select('emails.*')
            ->addSelect([
                'waiting_since' => $lastChange('created_at'),
                'waiting_reason' => $lastChange('reason'),
            ])
            ->withExists(['replies as has_draft' => fn ($q) => $q->where('status', EmailReplyStatus::Draft)])
            ->with(['mailConnection:id,name,email', 'assignee:id,name'])
            ->withCount('attachments')
            ->when($request->validated('assigned') === 'mine', fn ($q) => $q->where('assigned_to', $userId))
            ->when($request->validated('assigned') === 'unassigned', fn ($q) => $q->whereNull('assigned_to'))
            ->when($request->validated('intent'), fn ($q, $intent) => $q->where('intent', $intent))
            ->when($request->validated('mailbox'), fn ($q, $mailbox) => $q->where('mail_connection_id', $mailbox))
            ->orderByRaw("case urgency when 'high' then 0 when 'normal' then 1 else 2 end")
            ->orderBy('waiting_since')
            ->orderBy('id');

        return ReviewQueueItemResource::collection($query->paginate($request->integer('per_page', 25))->withQueryString())
            ->additional(['meta' => ['counts' => [
                'total' => $pending()->count(),
                'mine' => $pending()->where('assigned_to', $userId)->count(),
                'unassigned' => $pending()->whereNull('assigned_to')->count(),
                'failed' => $pending()->where('status', 'failed')->count(),
            ]]]);
    }
}
