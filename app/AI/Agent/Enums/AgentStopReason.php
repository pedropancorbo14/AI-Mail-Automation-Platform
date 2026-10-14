<?php

namespace App\AI\Agent\Enums;

/** Por qué terminó una ejecución del agente. */
enum AgentStopReason: string
{
    /** El modelo dio su respuesta final. */
    case EndTurn = 'end_turn';

    case MaxIterations = 'max_iterations';
    case MaxToolCalls = 'max_tool_calls';
    case Timeout = 'timeout';
    case Budget = 'budget';

    /** Los filtros de seguridad del modelo rechazaron el caso. */
    case Refusal = 'refusal';

    /** Error del proveedor o respuesta inválida. */
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::EndTurn => 'Respuesta final',
            self::MaxIterations => 'Límite de pasos alcanzado',
            self::MaxToolCalls => 'Límite de llamadas a herramientas alcanzado',
            self::Timeout => 'Tiempo máximo agotado',
            self::Budget => 'Límite de gasto alcanzado',
            self::Refusal => 'Rechazado por los filtros de seguridad del modelo',
            self::Error => 'Error',
        };
    }
}
