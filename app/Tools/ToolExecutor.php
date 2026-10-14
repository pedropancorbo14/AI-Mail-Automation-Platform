<?php

namespace App\Tools;

use App\Models\AiToolCall;
use App\Tenancy\TenantService;
use App\Tools\Contracts\AITool;
use App\Tools\Contracts\RequiresSender;
use App\Tools\Enums\ToolCallStatus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use Throwable;

/**
 * Único punto por el que se ejecuta una herramienta de la IA:
 * 1. existe en el catálogo (ToolRegistry) y está activada y disponible para la empresa (TenantToolService);
 * 2. los argumentos cumplen su esquema JSON;
 * 3. se puede usar en este contexto (p. ej. necesita remitente);
 * 4. se ejecuta dentro de la empresa del contexto, capturando cualquier
 *    error (el detalle interno solo va al log);
 * 5. el resultado se recorta si es demasiado grande;
 * y SIEMPRE queda registrado en ai_tool_calls. Nunca lanza: el modelo
 * recibe un ToolResult (datos o error) y decide.
 */
class ToolExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly TenantService $tenants,
        private readonly TenantToolService $tenantTools,
    ) {
    }

    /** @param  array<string, mixed>  $arguments */
    public function execute(string $name, array $arguments, ToolContext $context): ToolResult
    {
        return $this->tenants->runAs($context->tenant, function () use ($name, $arguments, $context) {
            Context::add('tenant_id', $context->tenant->id);
            $startedAt = hrtime(true);

            $tool = $this->registry->get($name);

            if ($tool === null) {
                return $this->record($name, $arguments, $context, ToolCallStatus::Rejected,
                    ToolResult::error("La herramienta {$name} no existe."), $startedAt);
            }

            if (! $this->tenantTools->isUsable($context->tenant, $name)) {
                return $this->record($name, $arguments, $context, ToolCallStatus::Rejected,
                    ToolResult::error("La herramienta {$name} no está disponible para esta empresa."), $startedAt);
            }

            if (($validationError = self::validate($tool, $arguments)) !== null) {
                return $this->record($name, $arguments, $context, ToolCallStatus::Rejected,
                    ToolResult::error("Argumentos no válidos para {$name}: {$validationError}"), $startedAt);
            }

            if ($tool instanceof RequiresSender && $context->senderEmail() === null) {
                return $this->record($name, $arguments, $context, ToolCallStatus::Rejected,
                    ToolResult::error("La herramienta {$name} solo puede usarse al procesar un email de un cliente."), $startedAt);
            }

            try {
                $result = $tool->execute($arguments, $context);
            } catch (Throwable $e) {
                Log::error('Error inesperado en una herramienta de la IA', [
                    'tool' => $name, 'exception' => $e::class, 'detail' => $e->getMessage(),
                ]);

                return $this->record($name, $arguments, $context, ToolCallStatus::Error,
                    ToolResult::error("Error interno al ejecutar {$name}. Inténtalo más tarde o deriva el caso a una persona."), $startedAt);
            }

            return $this->record($name, $arguments, $context,
                $result->ok ? ToolCallStatus::Success : ToolCallStatus::Error, self::limit($result), $startedAt);
        });
    }

    /** Primer error de validación en texto legible (p. ej. "/order_number: The data (integer) must match the type: string"). */
    private static function validate(AITool $tool, array $arguments): ?string
    {
        $result = (new Validator())->validate(Helper::toJSON((object) $arguments), Helper::toJSON($tool->inputSchema()));

        if ($result->isValid()) {
            return null;
        }

        $errors = (new ErrorFormatter())->format($result->error(), false);

        return collect($errors)->map(fn (string $message, string $path) => ($path === '/' ? '' : "{$path}: ") . $message)->first();
    }

    /** Resultados demasiado grandes se recortan (y se avisa) para no inflar el contexto ni el coste. */
    private static function limit(ToolResult $result): ToolResult
    {
        $max = (int) config('ai.tools.max_result_chars');
        $json = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);

        if (! $result->ok || mb_strlen($json) <= $max) {
            return $result;
        }

        return ToolResult::success([
            'truncated' => true,
            'note' => "Resultado recortado a {$max} caracteres: pide algo más concreto si necesitas el resto.",
            'partial' => mb_substr($json, 0, $max),
        ]);
    }

    /** @param  array<string, mixed>  $arguments */
    private function record(string $name, array $arguments, ToolContext $context, ToolCallStatus $status, ToolResult $result, int $startedAt): ToolResult
    {
        $call = AiToolCall::create([
            'ai_request_id' => $context->aiRequestId,
            'email_id' => $context->email?->id,
            'tool' => Str::limit($name, 100, ''),
            'arguments' => $arguments,
            'result' => $result->toArray(),
            'status' => $status,
            'error' => $result->error,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        Log::log($status === ToolCallStatus::Success ? 'info' : 'warning', 'Herramienta de la IA', [
            'ai_tool_call_id' => $call->id,
            'tool' => $name,
            'status' => $status->value,
            'duration_ms' => $call->duration_ms,
            'error' => $result->error,
        ]);

        return $result;
    }
}
