<?php

namespace App\Http\Controllers\Api\V1;

use App\AI\Exceptions\LLMException;
use App\AI\Remote\AIEngine;
use App\AI\Remote\AiServiceClient;
use App\AI\Remote\Exceptions\AiServiceUnavailableException;
use App\Documents\Search\SearchResult;
use App\Documents\Search\SemanticSearchService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\SearchKnowledgeRequest;
use App\Tenancy\TenantService;
use Illuminate\Http\JsonResponse;

/** Búsqueda por significado en la documentación de la empresa actual. */
class KnowledgeSearchController extends Controller
{
    public function __invoke(SearchKnowledgeRequest $request, TenantService $tenants): JsonResponse
    {
        $query = $request->validated('query');
        $limit = $request->validated('limit');

        if (AIEngine::current()->isRemote()) {
            // La búsqueda es de ai-service (la misma que usa la IA al redactar).
            try {
                $results = app(AiServiceClient::class)->searchKnowledge($tenants->currentOrFail(), $query, $limit);
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

        try {
            $results = app(SemanticSearchService::class)->search($query, $limit);
        } catch (LLMException) {
            // Detalle ya registrado en ai_requests y en el log.
            return response()->json(['message' => 'La búsqueda no está disponible en este momento.'], 503);
        }

        return response()->json([
            'data' => array_map(fn (SearchResult $result) => [
                'source' => $result->source(),
                'document_id' => $result->documentId,
                'document_title' => $result->documentTitle,
                'content' => $result->content,
                'metadata' => $result->metadata,
                'similarity' => $result->similarity,
            ], $results),
        ]);
    }
}
