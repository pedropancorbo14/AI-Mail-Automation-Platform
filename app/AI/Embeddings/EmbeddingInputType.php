<?php

namespace App\AI\Embeddings;

/**
 * Qué se está convirtiendo en vector. Algunos modelos (p. ej. Voyage, nomic)
 * generan vectores mejores si saben si es una pregunta o un documento;
 * bge-m3 no lo necesita, pero se indica igual por si se cambia de modelo.
 */
enum EmbeddingInputType: string
{
    /** Pregunta a buscar (p. ej. el contenido de un email). */
    case Query = 'query';

    /** Trozo de documentación que se guarda para ser encontrado. */
    case Document = 'document';
}
