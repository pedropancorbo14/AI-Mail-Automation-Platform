<?php

namespace App\AI\Embeddings;

use App\AI\Exceptions\LLMException;

/**
 * Proveedor de embeddings (Ollama local hoy). El resto de la aplicación solo
 * usa esta interfaz y EmbeddingResult, nunca la API concreta.
 */
interface EmbeddingProvider
{
    /** Nombre corto del proveedor ("ollama"), para registrar las peticiones. */
    public function name(): string;

    public function model(): string;

    /** Dimensiones de los vectores que devuelve. */
    public function dimensions(): int;

    /**
     * Un vector por texto, en el mismo orden. Divide en lotes si hace falta.
     *
     * @param  list<string>  $texts
     *
     * @throws LLMException mismos errores que los LLM (reintentable o no, sin la API key).
     */
    public function embed(array $texts, EmbeddingInputType $type): EmbeddingResult;
}
