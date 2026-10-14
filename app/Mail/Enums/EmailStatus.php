<?php

namespace App\Mail\Enums;

/**
 * Ciclo de vida de un email recibido (sección 12 de CLAUDE.md). La ingesta
 * solo crea emails en Received; el resto de estados los mueve el
 * procesamiento automático (Fase 9) y la revisión humana (Fase 13), siempre
 * con Email::transitionTo(), que solo permite los pasos de allowedNext().
 */
enum EmailStatus: string
{
    /** Guardado, pendiente de procesar. */
    case Received = 'received';

    /** La IA lo está procesando (clasificación y agente). */
    case Processing = 'processing';

    /** Hay respuesta propuesta por la IA y se están aplicando las reglas de envío. */
    case AiReview = 'ai_review';

    /** Necesita que una persona lo revise. */
    case HumanReview = 'human_review';

    /** Respuesta aprobada (por las reglas o por una persona), pendiente de envío. */
    case Approved = 'approved';

    /** Respuesta enviada. */
    case Sent = 'sent';

    /** No necesita respuesta: correo automático, spam, fuera de oficina... */
    case Ignored = 'ignored';

    /** El procesamiento o el envío fallaron. */
    case Failed = 'failed';

    /** @return list<self> Estados a los que se puede pasar desde este. */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Received => [self::Processing, self::Ignored],
            self::Processing => [self::AiReview, self::HumanReview, self::Ignored, self::Failed],
            self::AiReview => [self::Approved, self::HumanReview, self::Failed],
            // Una persona puede aprobar, descartar o mandarlo a procesar otra vez.
            self::HumanReview => [self::Approved, self::Ignored, self::Processing],
            self::Approved => [self::Sent, self::Failed],
            self::Sent => [],
            self::Ignored => [self::Processing, self::HumanReview],
            self::Failed => [self::Processing, self::HumanReview, self::Ignored],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibido',
            self::Processing => 'Procesando',
            self::AiReview => 'Revisión de la IA',
            self::HumanReview => 'Revisión humana',
            self::Approved => 'Aprobado',
            self::Sent => 'Enviado',
            self::Ignored => 'Sin respuesta',
            self::Failed => 'Error',
        };
    }
}
