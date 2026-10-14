<?php

namespace App\AI\Agent\Enums;

enum EmailReplyStatus: string
{
    /** Propuesta del agente, sin decidir. */
    case Draft = 'draft';

    /** Aprobada (automáticamente o por una persona), pendiente de envío. */
    case Approved = 'approved';

    /** Reclamada por un worker que la está enviando (evita envíos dobles). */
    case Sending = 'sending';

    /** Descartada por una persona. */
    case Rejected = 'rejected';

    case Sent = 'sent';

    /** No se pudo enviar (send_error); no se reintenta sola. */
    case Failed = 'failed';
}
