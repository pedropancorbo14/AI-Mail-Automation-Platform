<?php

namespace App\AI\Agent\Enums;

enum AgentRunStatus: string
{
    case Running = 'running';

    /** Terminó con una propuesta de respuesta (aunque pueda necesitar revisión). */
    case Completed = 'completed';

    /** No hubo respuesta: error, rechazo de seguridad o límite agotado (ver stop_reason). */
    case Failed = 'failed';
}
