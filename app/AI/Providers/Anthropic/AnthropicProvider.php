<?php

namespace App\AI\Providers\Anthropic;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\AI\Contracts\LLMProvider;
use App\AI\Data\LLMRequest;
use App\AI\Data\LLMResponse;
use App\AI\Data\LLMToolCall;
use App\AI\Data\LLMToolResult;
use App\AI\Data\LLMUsage;
use App\AI\Exceptions\LLMConfigurationException;
use App\AI\Exceptions\LLMException;
use App\AI\Exceptions\LLMInvalidResponseException;
use App\AI\Exceptions\LLMRateLimitException;
use App\AI\Exceptions\LLMRefusalException;
use App\AI\Exceptions\LLMUnavailableException;
use Closure;

/**
 * Claude a través del SDK oficial de Anthropic (anthropic-ai/sdk).
 *
 * - Formato de respuesta con structured outputs (outputConfig.format): la
 *   respuesta llega como JSON que cumple el esquema.
 * - Fallbacks de servidor opcionales: si los clasificadores de seguridad
 *   rechazan la petición, Anthropic la reintenta con otro modelo en la
 *   misma llamada; cada modelo factura sus tokens (usageByModel).
 * - Errores del SDK traducidos a LLMException (reintentable o no) con
 *   mensajes sin la API key.
 */
class AnthropicProvider implements LLMProvider
{
    /**
     * @param  Closure(): Client  $client  Se construye al primer uso (sin clave no se crea nunca).
     */
    public function __construct(
        private readonly Closure $client,
        private readonly string $defaultModel,
        private readonly bool $fallbacks = true,
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function generate(LLMRequest $request): LLMResponse
    {
        $model = $request->model ?? $this->defaultModel;
        $startedAt = hrtime(true);

        try {
            $message = ($this->client)()->beta->messages->create(...$this->params($request, $model));
        } catch (LLMException $e) {
            throw $e;
        } catch (RateLimitException $e) {
            throw new LLMRateLimitException('Anthropic: límite de peticiones alcanzado (429).', previous: $e);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new LLMConfigurationException("Anthropic rechazó la API key ({$e->status}): revisa ANTHROPIC_API_KEY.", previous: $e);
        } catch (NotFoundException|BadRequestException|UnprocessableEntityException $e) {
            throw new LLMConfigurationException("Anthropic rechazó la petición ({$e->status}): " . self::apiError($e), previous: $e);
        } catch (InternalServerException $e) {
            throw new LLMUnavailableException("Anthropic no está disponible ({$e->status}): " . self::apiError($e), previous: $e);
        } catch (APIStatusException $e) {
            throw new LLMUnavailableException("Anthropic respondió {$e->status}: " . self::apiError($e), previous: $e);
        } catch (APIConnectionException $e) {
            throw new LLMUnavailableException('No se pudo conectar con Anthropic (red o tiempo de espera agotado).', previous: $e);
        }

        $response = $this->toResponse($message, $model, (int) ((hrtime(true) - $startedAt) / 1_000_000));

        return match ($response->stopReason) {
            'refusal' => throw new LLMRefusalException(
                'El modelo rechazó la petición por seguridad' . self::refusalCategory($message) . '.',
                $response,
            ),
            'max_tokens' => throw new LLMInvalidResponseException(
                "La respuesta se cortó al llegar a max_tokens ({$request->maxTokens}).",
                $response,
            ),
            // Pide herramientas: aún no hay respuesta final que interpretar.
            'tool_use' => $response,
            default => $this->withData($response, $request),
        };
    }

    /** @return array<string, mixed> Parámetros con nombre de beta->messages->create(). */
    private function params(LLMRequest $request, string $model): array
    {
        $outputConfig = array_filter([
            'effort' => $request->effort,
            'format' => $request->jsonSchema === null ? null : ['type' => 'json_schema', 'schema' => $request->jsonSchema],
        ]);

        return array_filter([
            'model' => $model,
            'maxTokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => self::messages($request->messages),
            'tools' => $request->tools === [] ? null : array_map(fn (array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['input_schema'],
                // Argumentos que cumplen siempre el esquema (no se puede forzar
                // el uso de una herramienta en Opus 5.5; sí su forma).
                'strict' => true,
            ], $request->tools),
            'outputConfig' => $outputConfig ?: null,
            'fallbacks' => $this->fallbacks ? 'default' : null,
            'betas' => $this->fallbacks ? [AnthropicBeta::SERVER_SIDE_FALLBACK_2026_07_01->value] : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Conversación de LLMRequest → formato del SDK. Los turnos del asistente
     * se devuelven TAL CUAL (bloques del SDK con su razonamiento): editarlos
     * invalidaría el razonamiento y la API lo rechaza.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private static function messages(array $messages): array
    {
        return array_map(fn (array $message) => isset($message['tool_results'])
            ? ['role' => 'user', 'content' => array_map(fn (LLMToolResult $result) => [
                'type' => 'tool_result',
                'toolUseID' => $result->toolCallId,
                'content' => $result->content,
                'isError' => $result->isError,
            ], $message['tool_results'])]
            : ['role' => $message['role'], 'content' => $message['content']], $messages);
    }

    private function toResponse(BetaMessage $message, string $requestedModel, int $latencyMs): LLMResponse
    {
        $toolCalls = [];
        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $toolCalls[] = new LLMToolCall($block->id, $block->name, (array) $block->input);
            }
        }

        [$usage, $usageByModel, $usedFallback] = self::usage($message, $requestedModel);

        return new LLMResponse(
            provider: $this->name(),
            model: $message->model,
            text: $text === '' ? null : $text,
            data: null,
            usage: $usage,
            stopReason: $message->stopReason,
            latencyMs: $latencyMs,
            requestId: $message->id,
            usedFallback: $usedFallback,
            usageByModel: $usageByModel,
            toolCalls: $toolCalls,
            assistantContent: $message->content,
        );
    }

    /**
     * Tokens totales y por modelo. Con fallbacks, usage.iterations trae una
     * entrada por modelo que intervino ("message" y "fallback_message").
     *
     * @return array{0: LLMUsage, 1: list<array{model: string, usage: LLMUsage}>, 2: bool}
     */
    private static function usage(BetaMessage $message, string $requestedModel): array
    {
        $iterations = array_values(array_filter(
            $message->usage->iterations ?? [],
            fn ($item) => in_array($item->type ?? null, ['message', 'fallback_message'], true),
        ));

        if ($iterations === []) {
            $usage = new LLMUsage(
                $message->usage->inputTokens,
                $message->usage->outputTokens,
                (int) $message->usage->cacheCreationInputTokens,
                (int) $message->usage->cacheReadInputTokens,
            );

            return [$usage, [['model' => $message->model, 'usage' => $usage]], false];
        }

        $byModel = array_map(fn ($item) => [
            'model' => $item->model ?? $requestedModel,
            'usage' => new LLMUsage($item->inputTokens, $item->outputTokens, $item->cacheCreationInputTokens, $item->cacheReadInputTokens),
        ], $iterations);

        $total = new LLMUsage(
            array_sum(array_map(fn ($part) => $part['usage']->inputTokens, $byModel)),
            array_sum(array_map(fn ($part) => $part['usage']->outputTokens, $byModel)),
            array_sum(array_map(fn ($part) => $part['usage']->cacheWriteTokens, $byModel)),
            array_sum(array_map(fn ($part) => $part['usage']->cacheReadTokens, $byModel)),
        );

        $usedFallback = collect($iterations)->contains(fn ($item) => $item->type === 'fallback_message');

        return [$total, $byModel, $usedFallback];
    }

    /** Con esquema, el texto debe ser JSON válido (structured outputs). */
    private function withData(LLMResponse $response, LLMRequest $request): LLMResponse
    {
        if ($request->jsonSchema === null) {
            return $response;
        }

        $data = json_decode((string) $response->text, true);

        if (! is_array($data)) {
            throw new LLMInvalidResponseException('La respuesta del modelo no es el JSON esperado.', $response);
        }

        return $response->withData($data);
    }

    private static function refusalCategory(BetaMessage $message): string
    {
        $category = $message->stopDetails->category ?? null;

        return $category ? " (categoría: {$category})" : '';
    }

    /** Tipo y mensaje de error de la API (no incluyen la clave). */
    private static function apiError(APIStatusException $e): string
    {
        $error = is_array($e->body) ? ($e->body['error'] ?? []) : [];

        return trim(($error['type'] ?? 'error') . ': ' . ($error['message'] ?? 'sin detalle'));
    }
}
