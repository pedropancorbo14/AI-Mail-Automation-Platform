<?php

namespace App\AI\Remote;

use App\AI\Remote\Exceptions\AiServiceUnavailableException;
use App\Models\Tenant;
use App\Tenancy\TenantService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente de la API interna síncrona de ai-service (/internal/v1): lo que el
 * panel necesita al momento. La empresa siempre la pone Laravel (la del
 * usuario autenticado), nunca el cliente.
 */
class AiServiceClient
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly RemoteCallRecorder $recorder,
    ) {
    }

    /**
     * Búsqueda en la documentación de la empresa (lo que encontraría la IA).
     * La llamada de embeddings queda en ai_requests como las demás.
     *
     * @return list<array{source: string, chunk_id: int, document_id: int, document_title: string, content: string, metadata: array<string, mixed>, similarity: float}>
     *
     * @throws AiServiceUnavailableException
     */
    public function searchKnowledge(Tenant $tenant, string $query, ?int $limit = null): array
    {
        try {
            $response = $this->http()->post('/internal/v1/knowledge/search', array_filter([
                'tenant_id' => $tenant->id,
                'query' => $query,
                'limit' => $limit,
            ], fn ($value) => $value !== null));
        } catch (Throwable $e) {
            Log::warning('ai-service no responde a la búsqueda', ['error' => $e->getMessage()]);
            throw new AiServiceUnavailableException('La búsqueda no está disponible ahora mismo. Inténtalo en unos minutos.', previous: $e);
        }
        if (! $response->successful()) {
            Log::warning('ai-service rechazó la búsqueda', ['status' => $response->status(), 'detail' => $response->json('detail')]);
            throw new AiServiceUnavailableException('La búsqueda no está disponible ahora mismo. Inténtalo en unos minutos.');
        }

        $this->tenants->runAs($tenant, fn () => $this->recorder->record(null, $response->json('calls') ?? []));

        return $response->json('results') ?? [];
    }

    /**
     * Si cada herramienta tiene datos que consultar en la empresa. Un valor
     * null (o todo null si ai-service no responde) = desconocido.
     *
     * @return array<string, bool|null>
     */
    public function toolAvailability(Tenant $tenant): array
    {
        try {
            $response = $this->http()->get('/internal/v1/tools', ['tenant_id' => $tenant->id]);
            if ($response->successful()) {
                return collect($response->json('tools') ?? [])
                    ->mapWithKeys(fn (array $tool) => [$tool['name'] => $tool['available']])->all();
            }
            Log::warning('ai-service no dio la disponibilidad de las herramientas', ['status' => $response->status()]);
        } catch (Throwable $e) {
            Log::warning('ai-service no responde (herramientas)', ['error' => $e->getMessage()]);
        }

        return [];
    }

    private function http(): PendingRequest
    {
        $token = (string) config('ai.remote.internal_token');
        if ($token === '') {
            throw new AiServiceUnavailableException('Falta AI_SERVICE_INTERNAL_TOKEN para hablar con ai-service.');
        }

        return Http::baseUrl(rtrim((string) config('ai.remote.service_url'), '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) config('ai.remote.http_timeout'));
    }
}
