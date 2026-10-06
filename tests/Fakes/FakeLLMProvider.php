<?php

namespace Tests\Fakes;

use App\AI\Contracts\LLMProvider;
use App\AI\Data\LLMRequest;
use App\AI\Data\LLMResponse;
use App\AI\Data\LLMUsage;
use Throwable;

/**
 * Proveedor de IA en memoria para probar el código que usa LLMProvider sin
 * llamar a ninguna API. Devuelve en orden las respuestas encoladas (o lanza
 * las excepciones encoladas) y guarda las peticiones recibidas.
 */
class FakeLLMProvider implements LLMProvider
{
    /** @var list<LLMRequest> */
    public array $requests = [];

    /** @var list<LLMResponse|array<string, mixed>|Throwable> */
    private array $queue = [];

    public function name(): string
    {
        return 'fake';
    }

    /** Encola una respuesta: un LLMResponse, los datos JSON que "devolverá" el modelo o una excepción. */
    public function respondWith(LLMResponse|array|Throwable ...$responses): static
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    public function generate(LLMRequest $request): LLMResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new \LogicException('FakeLLMProvider: no hay respuestas encoladas.');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next instanceof LLMResponse ? $next : self::response($next);
    }

    /** Respuesta típica con datos JSON. */
    public static function response(array $data = [], string $model = 'claude-opus-5-5', ?LLMUsage $usage = null, int $latencyMs = 850): LLMResponse
    {
        $usage ??= new LLMUsage(1200, 300);

        return new LLMResponse(
            provider: 'fake',
            model: $model,
            text: json_encode($data),
            data: $data,
            usage: $usage,
            stopReason: 'end_turn',
            latencyMs: $latencyMs,
            requestId: 'req_fake',
            usageByModel: [['model' => $model, 'usage' => $usage]],
        );
    }
}
