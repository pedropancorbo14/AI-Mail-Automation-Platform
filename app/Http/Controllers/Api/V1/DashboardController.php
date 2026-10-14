<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailListResource;
use App\Models\Email;
use App\Observability\DashboardStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    /** Resumen de los últimos N días (7, 30 o 90) para el inicio del panel. */
    public function __invoke(Request $request, DashboardStats $stats): JsonResponse
    {
        Gate::authorize('viewAny', Email::class);

        $request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]]);
        $days = $request->integer('days', 30);

        return response()->json(['data' => [
            ...$stats->summary($days),
            'needs_attention' => EmailListResource::collection($stats->needsAttention()),
        ]]);
    }
}
