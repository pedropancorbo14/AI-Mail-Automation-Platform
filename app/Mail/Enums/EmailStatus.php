<?php

namespace App\Mail\Enums;

/**
 * Ciclo de vida de un email recibido (sección 12 de CLAUDE.md). La ingesta
 * (Fase 4) solo crea emails en Received; el resto de estados los mueve el
 * procesamiento con IA y la revisión humana (Fases 9 y 13).
 */
enum EmailStatus: string
{
    /** Guardado, pendiente de procesar. */
    case Received = 'received';

    /** La IA lo está procesando. */
    case Processing = 'processing';

    /** Respuesta generada por la IA, pendiente de validar por las reglas. */
    case AiReview = 'ai_review';

    /** Necesita que una persona lo revise. */
    case HumanReview = 'human_review';

    /** Respuesta aprobada, pendiente de envío. */
    case Approved = 'approved';

    /** Respuesta enviada. */
    case Sent = 'sent';

    /** El procesamiento falló. */
    case Failed = 'failed';
}
