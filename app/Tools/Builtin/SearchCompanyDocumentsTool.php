<?php

namespace App\Tools\Builtin;

use App\AI\Exceptions\LLMException;
use App\Documents\Search\SearchResult;
use App\Documents\Search\SemanticSearchService;
use App\Tools\Contracts\AITool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * Busca en la documentación interna de la empresa (RAG, Fase 6) y devuelve
 * los fragmentos relevantes con su fuente, para responder citándola.
 */
class SearchCompanyDocumentsTool implements AITool
{
    private const int MAX_RESULTS = 8;

    public function __construct(private readonly SemanticSearchService $search)
    {
    }

    public function name(): string
    {
        return 'search_company_documents';
    }

    public function description(): string
    {
        return 'Busca en la documentación interna de la empresa (políticas de devoluciones, envíos, garantía, '
            . 'pagos, FAQs, manuales...) y devuelve los fragmentos más relevantes con su fuente. Úsala antes de '
            . 'afirmar plazos, precios, condiciones o procedimientos: responde solo con lo que digan los fragmentos '
            . 'y cita su fuente. Si no devuelve nada relevante, no inventes la respuesta.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Qué buscar, en lenguaje natural (p. ej. "plazo para devolver un producto roto").',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Número de fragmentos (1 a ' . self::MAX_RESULTS . '; por defecto 5).',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        // Sin minimum/maximum en el esquema (Claude no los admite en herramientas estrictas): se acota aquí.
        $limit = max(1, min(self::MAX_RESULTS, (int) ($arguments['limit'] ?? 5)));

        try {
            $results = $this->search->search((string) $arguments['query'], $limit, email: $context->email);
        } catch (LLMException) {
            return ToolResult::error('La búsqueda en la documentación no está disponible ahora mismo.');
        }

        if ($results === []) {
            return ToolResult::success([
                'results' => [],
                'note' => 'No hay documentación relevante para esta consulta. No inventes la información: '
                    . 'si hace falta, indica que una persona responderá.',
            ]);
        }

        return ToolResult::success([
            'results' => array_map(fn (SearchResult $result) => [
                'source' => $result->source(),
                'document_id' => $result->documentId,
                'similarity' => $result->similarity,
                'content' => $result->content,
            ], $results),
        ]);
    }
}
