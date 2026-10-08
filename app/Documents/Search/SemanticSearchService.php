<?php

namespace App\Documents\Search;

use App\AI\Embeddings\EmbeddingInputType;
use App\AI\Embeddings\EmbeddingService;
use App\AI\Exceptions\LLMException;
use App\Documents\Enums\DocumentStatus;
use App\Models\DocumentChunk;
use App\Models\Email;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda por significado en la documentación de la empresa actual:
 * convierte la pregunta en vector y busca los trozos más cercanos (distancia
 * coseno, índice HNSW de pgvector) de documentos listos.
 *
 * Multi-tenant: el índice HNSW busca vecinos en TODA la tabla y luego se
 * filtra por tenant_id, así que con muchas empresas podría devolver menos
 * resultados de los pedidos. Se activa hnsw.iterative_scan (pgvector 0.8)
 * para que siga buscando hasta completar el límite dentro de la empresa.
 */
class SemanticSearchService
{
    public function __construct(private readonly EmbeddingService $embeddings)
    {
    }

    /**
     * @return list<SearchResult> de más a menos parecido
     *
     * @throws LLMException ya registrada en ai_requests.
     */
    public function search(string $query, ?int $limit = null, ?float $minSimilarity = null, ?Email $email = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $limit ??= (int) config('ai.rag.search_limit');
        $minSimilarity ??= (float) config('ai.rag.min_similarity');

        $vector = $this->embeddings->embed([$query], EmbeddingInputType::Query, EmbeddingService::PURPOSE_SEARCH, $email)->vectors[0];

        $rows = DB::transaction(function () use ($vector, $limit, $minSimilarity) {
            // Solo para esta transacción: búsqueda iterativa hasta llenar el límite tras filtrar.
            DB::statement('SET LOCAL hnsw.iterative_scan = relaxed_order');

            return DocumentChunk::query()   // BelongsToTenant: solo la empresa actual
                ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
                ->where('documents.status', DocumentStatus::Ready)
                ->select('document_chunks.id', 'document_chunks.document_id', 'document_chunks.content', 'document_chunks.metadata', 'documents.title')
                ->selectVectorDistance('document_chunks.embedding', $vector, 'distance')
                ->whereVectorDistanceLessThan('document_chunks.embedding', $vector, 1 - $minSimilarity)
                ->orderByVectorDistance('document_chunks.embedding', $vector)
                ->limit($limit)
                ->get();
        });

        // relaxed_order puede devolver el orden ligeramente alterado: se reordena aquí.
        return $rows
            ->map(fn (DocumentChunk $row) => new SearchResult(
                chunkId: $row->id,
                documentId: $row->document_id,
                documentTitle: $row->title,
                content: $row->content,
                metadata: $row->metadata ?? [],
                similarity: round(1 - (float) $row->distance, 4),
            ))
            ->sortByDesc('similarity')
            ->values()
            ->all();
    }
}
