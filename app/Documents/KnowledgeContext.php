<?php

namespace App\Documents;

use App\Documents\Search\SearchResult;

/**
 * Documentación relevante para una pregunta, lista para un prompt y con sus
 * fuentes numeradas ([1], [2]...) para que la respuesta pueda citarlas.
 */
final readonly class KnowledgeContext
{
    /** @param  list<SearchResult>  $results */
    public function __construct(
        public string $query,
        public array $results,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    /**
     * Bloque para el prompt. El contenido va como DATO dentro de etiquetas;
     * las etiquetas que traiga el propio texto se neutralizan para que un
     * documento no pueda "salirse" del bloque e inyectar instrucciones.
     */
    public function toPrompt(): string
    {
        if ($this->isEmpty()) {
            return "<documentacion>\n(No hay documentación relevante para esta consulta.)\n</documentacion>";
        }

        $sources = [];

        foreach ($this->results as $i => $result) {
            $number = $i + 1;
            $title = self::neutralize($result->source());
            $sources[] = "<fuente id=\"{$number}\" origen=\"" . htmlspecialchars($title, ENT_QUOTES) . "\">\n"
                . self::neutralize($result->content) . "\n</fuente>";
        }

        return "<documentacion>\n" . implode("\n", $sources) . "\n</documentacion>";
    }

    /** @return list<array{id: int, source: string, document_id: int, chunk_id: int, similarity: float}> */
    public function sources(): array
    {
        return array_map(fn (SearchResult $result, int $i) => [
            'id' => $i + 1,
            'source' => $result->source(),
            'document_id' => $result->documentId,
            'chunk_id' => $result->chunkId,
            'similarity' => $result->similarity,
        ], $this->results, array_keys($this->results));
    }

    private static function neutralize(string $text): string
    {
        return preg_replace('~<\s*(/?)\s*(documentacion|fuente)\b[^>]*>~i', '[$1$2]', $text);
    }
}
