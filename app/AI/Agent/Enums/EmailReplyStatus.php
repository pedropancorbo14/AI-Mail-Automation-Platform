<?php

namespace App\AI\Agent\Enums;

enum EmailReplyStatus: string
{
    /** Propuesta del agente, sin decidir. */
    case Draft = 'draft';

    /** Aprobada (automáticamente o por una persona), pendiente de envío. */
    case Approved = 'approved';

    /** Descartada por una persona. */
    case Rejected = 'rejected';

    case Sent = 'sent';
}
