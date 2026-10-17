<?php

namespace App\Http\Controllers\Api\V1;

use App\Automation\Review\ReviewStats;
use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\ListReviewsRequest;
use App\Http\Resources\EmailReviewResource;
use App\Models\Email;
use App\Models\EmailReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Historial de la revisión humana (Fase 13): todas las decisiones de las
 * personas sobre los emails de la empresa y su resumen por periodo.
 */
class ReviewHistoryController extends Controller
{
    public function index(ListReviewsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Email::class);

        $reviews = EmailReview::query()
            ->with(['user:id,name', 'email:id,subject,from_email,from_name,intent,status'])
            ->when($request->validated('decision'), fn ($q, $decision) => $q->where('decision', $decision))
            ->when($request->validated('user'), fn ($q, $user) => $q->where('user_id', $user))
            ->when($request->validated('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->validated('to'), fn ($q, $to) => $q->where('created_at', '<', now()->parse($to)->addDay()->startOfDay()))
            ->latest('id');

        return EmailReviewResource::collection($reviews->paginate($request->integer('per_page', 25))->withQueryString());
    }

    /** Resumen de los últimos N días (7, 30 o 90). */
    public function summary(Request $request, ReviewStats $stats): JsonResponse
    {
        Gate::authorize('viewAny', Email::class);

        $request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]]);

        return response()->json(['data' => $stats->summary($request->integer('days', 30))]);
    }
}
