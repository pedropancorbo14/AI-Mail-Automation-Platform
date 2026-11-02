<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Tenancy\Onboarding;
use App\Tenancy\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Lista de primeros pasos del Resumen (Fase 17). */
class OnboardingController extends Controller
{
    public function __construct(private readonly TenantService $tenancy, private readonly Onboarding $onboarding)
    {
    }

    public function show(): JsonResponse
    {
        $tenant = $this->tenancy->currentOrFail();
        Gate::authorize('view', $tenant);

        $steps = $this->onboarding->steps($tenant);

        return response()->json(['data' => [
            'steps' => $steps,
            'completed' => count(array_filter($steps, fn (array $step) => $step['done'])),
            'dismissed' => $tenant->onboarding_dismissed_at !== null,
        ]]);
    }

    /** La oculta para toda la empresa (quien configura la plataforma). */
    public function dismiss(): JsonResponse
    {
        $tenant = $this->tenancy->currentOrFail();
        Gate::authorize(Permission::AUTOMATION_MANAGE);

        $tenant->forceFill(['onboarding_dismissed_at' => now()])->save();

        return $this->show();
    }
}
