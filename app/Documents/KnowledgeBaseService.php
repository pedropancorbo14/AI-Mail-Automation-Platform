<?php

namespace App\Documents;

use App\AI\Exceptions\LLMException;
use App\Documents\Search\SemanticSearchService;
use App\Mail\Support\HtmlToText;
use App\Models\Email;

/**
 * Punto de entrada a la base de conocimiento para el resto de la app (el
 * agente de la Fase 8): dada una pregunta o un email, devuelve la
 * documentación relevante de la empresa actual con sus fuentes.
 */
class KnowledgeBaseService
{
    /** Caracteres del email usados como pregunta (el principio es lo relevante). */
    private const int EMAIL_QUERY_CHARS = 2000;

    public function __construct(private readonly SemanticSearchService $search)
    {
    }

    /**
     * @throws LLMException si el proveedor de embeddings falla.
     */
    public function contextFor(string $question, ?int $limit = null, ?Email $email = null): KnowledgeContext
    {
        return new KnowledgeContext($question, $this->search->search($question, $limit, email: $email));
    }

    /**
     * Documentación relevante para responder un email (asunto + cuerpo).
     *
     * @throws LLMException
     */
    public function contextForEmail(Email $email, ?int $limit = null): KnowledgeContext
    {
        return $this->contextFor(self::emailQuery($email), $limit, $email);
    }

    public static function emailQuery(Email $email): string
    {
        $body = $email->text_body ?: ($email->html_body ? HtmlToText::convert($email->html_body) : '');

        return mb_substr(trim($email->subject . "\n\n" . $body), 0, self::EMAIL_QUERY_CHARS);
    }
}
