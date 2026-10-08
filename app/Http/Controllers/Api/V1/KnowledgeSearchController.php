<?php

namespace App\Http\Controllers\Api\V1;

use App\AI\Exceptions\LLMException;
use App\Documents\Search\SearchResult;
use App\Documents\Search\SemanticSearchService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\SearchKnowledgeRequest;
use Illuminate\Http\JsonResponse;

/** Búsqueda por significado en la documentación de la empresa actual. */
class KnowledgeSearchController extends Controller
{
    public function __invoke(SearchKnowledgeRequest $request, SemanticSearchService $search): JsonResponse
    {
        try {
            $results = $search->search($request->validated('query'), $request->validated('limit'));
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
