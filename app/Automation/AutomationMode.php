<?php

namespace App\Automation;

/**
 * Cuánto automatiza la plataforma en una empresa. Por defecto Draft: la IA
 * prepara la respuesta pero una persona la revisa siempre. Auto (enviar sin
 * persona) lo activa la empresa explícitamente.
 */
enum AutomationMode: string
{
    /** Sin IA: los emails van directamente a revisión humana. */
    case Off = 'off';

    /** La IA clasifica y propone la respuesta; una persona revisa siempre. */
    case Draft = 'draft';

    /** Además, se envían solas las respuestas seguras de las intenciones permitidas. */
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Desactivada',
            self::Draft => 'Borradores',
            self::Auto => 'Automática',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Off => 'Sin IA: los emails van directamente a revisión humana.',
            self::Draft => 'La IA clasifica y prepara la respuesta; una persona la revisa siempre antes de enviar.',
            self::Auto => 'Como borradores, pero las respuestas seguras de las intenciones permitidas se envían solas.',
        };
    }

    public function usesAI(): bool
    {
        return $this !== self::Off;
    }
}
