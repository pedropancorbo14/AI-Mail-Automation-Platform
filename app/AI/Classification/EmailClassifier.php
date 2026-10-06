<?php

namespace App\AI\Classification;

use App\AI\AIService;
use App\AI\Exceptions\LLMException;
use App\Mail\Enums\RecipientType;
use App\Mail\Support\HtmlToText;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\EmailRecipient;

/**
 * Clasifica un email con la IA (EmailClassificationPrompt). El contenido
 * del email se envía como dato no fiable dentro de <email>...</email>; las
 * etiquetas que traiga el propio email se neutralizan para que no pueda
 * "cerrar" el bloque y colar instrucciones. Debe ejecutarse en el tenant
 * del email.
 */
class EmailClassifier
{
    public function __construct(
        private readonly AIService $ai,
        private readonly EmailClassificationPrompt $prompt,
    ) {
    }

    /**
     * @throws LLMException ya registrada en ai_requests.
     */
    public function classify(Email $email): EmailClassification
    {
        [$content, $truncated] = $this->content($email);

        $result = $this->ai->run(
            $this->prompt,
            $content,
            maxTokens: (int) config('ai.classification.max_tokens'),
            effort: config('ai.classification.effort'),
            email: $email,
        );

        return EmailClassification::fromArray($result->response->data ?? [], $truncated, $result->record->id);
    }

    /**
     * Texto que se envía al modelo y si se ha recortado el cuerpo.
     *
     * @return array{0: string, 1: bool}
     */
    public function content(Email $email): array
    {
        $email->loadMissing('recipients', 'attachments');

        $body = $email->text_body ?: ($email->html_body ? HtmlToText::convert($email->html_body) : '');
        $limit = (int) config('ai.classification.max_body_chars');
        $length = mb_strlen($body);
        $truncated = $length > $limit;

        $lines = array_filter([
            'De: ' . self::address($email->from_email, $email->from_name),
            self::recipientLine('Para', $email, RecipientType::To),
            self::recipientLine('Cc', $email, RecipientType::Cc),
            self::recipientLine('Responder a', $email, RecipientType::ReplyTo),
            'Asunto: ' . $email->subject,
            $email->sent_at ? 'Fecha: ' . $email->sent_at->toIso8601String() : null,
            $email->attachments->isEmpty() ? null : 'Adjuntos: ' . $email->attachments
                ->map(fn (EmailAttachment $a) => "{$a->filename} ({$a->content_type})")->implode(', '),
            // Recorte explícito: el modelo sabe que no ve el email entero.
            $truncated ? "[Cuerpo recortado: se muestran los primeros {$limit} de {$length} caracteres]" : null,
        ]);

        $text = implode("\n", $lines) . "\n\n" . ($truncated ? mb_substr($body, 0, $limit) : $body);

        return ["<email>\n" . self::neutralize($text) . "\n</email>", $truncated];
    }

    private static function recipientLine(string $label, Email $email, RecipientType $type): ?string
    {
        $addresses = $email->recipients
            ->where('type', $type)
            ->map(fn (EmailRecipient $r) => self::address($r->email, $r->name));

        return $addresses->isEmpty() ? null : "{$label}: " . $addresses->implode(', ');
    }

    private static function address(?string $email, ?string $name): string
    {
        return $name ? "{$name} <{$email}>" : (string) ($email ?? 'desconocido');
    }

    /** Las etiquetas <email> del contenido no pueden abrir ni cerrar el bloque de datos. */
    private static function neutralize(string $text): string
    {
        return preg_replace('~<\s*(/?)\s*email\s*>~i', '[$1email]', $text);
    }
}
