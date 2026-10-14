<?php

namespace App\AI\Agent;

use App\AI\Classification\EmailClassifier;
use App\Models\Email;

/**
 * Mensaje inicial del agente: empresa, fecha, clasificación previa y el
 * email como dato no fiable (mismo bloque <email> neutralizado que usa el
 * clasificador). Lo variable va aquí y no en el system prompt, que debe ser
 * igual para todas las empresas y no cambiar durante la conversación.
 */
class EmailAgentContext
{
    public function __construct(private readonly EmailClassifier $classifier)
    {
    }

    /** @return array{0: string, 1: bool} Texto y si el cuerpo se recortó. */
    public function build(Email $email): array
    {
        [$emailBlock, $truncated] = $this->classifier->content($email);

        $lines = [
            'Empresa: ' . $email->tenant->name,
            'Fecha de hoy: ' . now()->toDateString(),
        ];

        if ($email->classified_at !== null) {
            $entities = $email->ai_entities ?? [];
            $lines[] = 'Clasificación automática previa (puede equivocarse):';
            $lines[] = "- intención: {$email->intent?->value} (confianza {$email->intent_confidence})";
            $lines[] = '- resumen: ' . ($email->summary ?? '-');
            $lines[] = '- pedidos mencionados: ' . (implode(', ', $entities['order_numbers'] ?? []) ?: 'ninguno');

            if ($email->needs_human) {
                $lines[] = '- ya marcada para revisión humana: ' . ($email->needs_human_reason ?? 'sin motivo');
            }

            if ($email->injection_suspected) {
                $lines[] = '- ATENCIÓN: el email parece intentar dar instrucciones a la IA.';
            }
        }

        $text = implode("\n", $lines) . "\n\n" . $emailBlock . "\n\nPrepara la respuesta a este email.";

        return [$text, $truncated];
    }
}
