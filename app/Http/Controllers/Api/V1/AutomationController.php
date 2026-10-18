<?php

namespace App\Http\Controllers\Api\V1;

use App\AI\Remote\AIEngine;
use App\AI\AIService;
use App\AI\Classification\EmailIntent;
use App\Automation\AutomationMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\UpdateAutomationRequest;
use App\Models\Permission;
use App\Security\Audit\AuditLogger;
use App\Tenancy\TenantService;
use App\Tools\TenantToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Configuración de la IA de la empresa: modo de automatización, intenciones
 * que se responden solas y herramientas que puede usar el agente. Siempre
 * la de la empresa del usuario (sin id en la URL).
 */
class AutomationController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly TenantToolService $tools,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(): JsonResponse
    {
        Gate::authorize(Permission::TENANT_VIEW);

        return $this->settings();
    }

    public function update(UpdateAutomationRequest $request): JsonResponse
    {
        $tenant = $this->tenants->currentOrFail();

        try {
            $tenant->configureAutomation(
                AutomationMode::from($request->validated('mode', $tenant->automation_mode->value)),
                $request->has('auto_send_intents') ? $request->validated('auto_send_intents') : null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['auto_send_intents' => $e->getMessage()]);
        }

        if ($changes = AuditLogger::changesOf($tenant, ['automation_mode', 'auto_send_intents'])) {
            $this->audit->record('automation.updated', $tenant, $tenant->name, $changes);
        }

        return $this->settings();
    }

    /** Activa o desactiva una herramienta del agente para la empresa. */
    public function updateTool(Request $request, string $tool): JsonResponse
    {
        Gate::authorize(Permission::AUTOMATION_MANAGE);

        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        try {
            $enabled ? $this->tools->enable($this->tenants->currentOrFail(), $tool) : $this->tools->disable($this->tenants->currentOrFail(), $tool);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $this->audit->record('automation.tool_changed', subjectLabel: $tool, metadata: ['tool' => $tool, 'enabled' => $enabled]);

        return $this->settings();
    }

    private function settings(): JsonResponse
    {
        $tenant = $this->tenants->currentOrFail()->fresh();

        return response()->json(['data' => [
            'mode' => $tenant->automation_mode->value,
            'modes' => array_map(fn (AutomationMode $mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'description' => $mode->description(),
            ], AutomationMode::cases()),
            'auto_send_intents' => array_map(fn (EmailIntent $intent) => $intent->value, $tenant->autoSendIntents()),
            // false = las de la configuración de la plataforma.
            'auto_send_intents_custom' => $tenant->auto_send_intents !== null,
            'intents' => array_map(fn (EmailIntent $intent) => [
                'value' => $intent->value,
                'allows_auto_send' => $intent->allowsAutoSend(),
            ], EmailIntent::cases()),
            'tools' => array_map(fn (array $tool) => [...$tool, 'source' => $tool['source'] === 'empresa' ? 'company' : 'default'], AIEngine::current()->isRemote() ? $this->tools->catalogStatusFor($tenant) : $this->tools->statusFor($tenant)),
            'platform' => [
                'ai_configured' => AIService::enabled(),
                'auto_send_enabled' => (bool) config('ai.automation.auto_send_enabled'),
                'model' => config('ai.anthropic.model'),
                'min_confidence' => (float) config('ai.agent.min_confidence'),
                'max_auto_replies_per_address' => (int) config('ai.automation.max_auto_replies_per_address'),
            ],
        ]]);
    }
}
