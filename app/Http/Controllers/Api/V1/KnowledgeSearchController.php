<?php

namespace App\Http\Controllers\Api\V1;

use App\AI\Remote\AiServiceClient;
use App\AI\Remote\Exceptions\AiServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\SearchKnowledgeRequest;
use App\Tenancy\TenantService;
use Illuminate\Http\JsonResponse;

/**
 * Búsqueda por significado en la documentación de la empresa actual. La hace
 * ai-service (la misma que usa la IA al redactar).
 */
class KnowledgeSearchController extends Controller
{
    public function __invoke(SearchKnowledgeRequest $request, TenantService $tenants, AiServiceClient $client): JsonResponse
    {
        try {
            $results = $client->searchKnowledge($tenants->currentOrFail(), $request->validated('query'), $request->validated('limit'));
        } catch (AiServiceUnavailableException) {
            return response()->json(['message' => 'La búsqueda no está disponible en este momento.'], 503);
        }

        return response()->json(['data' => array_map(fn (array $result) => [
            'source' => $result['source'],
            'document_id' => $result['document_id'],
            'document_title' => $result['document_title'],
            'content' => $result['content'],
            'metadata' => $result['metadata'] ?: null,
            'similarity' => $result['similarity'],
        ], $results)]);
    }
}
