<?php

namespace App\AI\Providers\Simulated;

use App\AI\Contracts\LLMProvider;
use App\AI\Data\LLMRequest;
use App\AI\Data\LLMResponse;
use App\AI\Data\LLMToolCall;
use App\AI\Data\LLMToolResult;
use App\AI\Data\LLMUsage;
use App\AI\Exceptions\LLMInvalidResponseException;
use Illuminate\Support\Str;

/**
 * IA SIMULADA para desarrollo (AI_PROVIDER=simulated): responde sin llamar a
 * ninguna API ni gastar dinero, con reglas sencillas por palabras clave, para
 * poder probar en el stack el flujo completo (clasificación → agente con
 * herramientas → decisión → envío), las métricas y los dashboards. Nunca en
 * producción (lo impide AIServiceProvider). No entiende el texto: solo sirve
 * para ejercitar la plataforma, no para medir la calidad de las respuestas.
 *
 * Reconoce la petición por el esquema que se le pide: clasificación (campo
 * "intent") o propuesta de respuesta del agente (campo "reply_body").
 */
class SimulatedLLMProvider implements LLMProvider
{
    public const string MODEL = 'simulated';

    /** Palabras clave → intención (la primera que coincide gana; orden = prioridad). */
    private const array RULES = [
        'spam' => ['bitcoin', 'has ganado', 'premio', 'haz clic', 'click here', 'lottery', 'verifica tu cuenta'],
        'auto_reply' => ['fuera de la oficina', 'out of office', 'de vacaciones', 'respuesta automática'],
        'complaint' => ['inaceptable', 'abogado', 'denuncia', 'queja', 'vergüenza', 'unacceptable'],
        'billing_issue' => ['cobrado dos veces', 'doble cobro', 'cargo duplicado', 'charged twice'],
        'cancellation' => ['cancelar', 'cancel'],
        'return_request' => ['devolver', 'devolución', 'return', 'refund', 'roto', 'broken'],
        'invoice_request' => ['factura', 'invoice'],
        'order_status' => ['dónde está mi pedido', 'donde esta mi pedido', 'estado de mi pedido', 'mi pedido', 'where is my order', 'my order'],
        'product_question' => ['envío', 'envíos', 'enviáis', 'ship', 'plazo', 'garantía', 'warranty', '¿', '?'],
    ];

    /** Frases típicas de prompt injection. */
    private const array INJECTION = [
        'ignora las instrucciones', 'ignora tus instrucciones', 'ignore previous instructions',
        'ignore all previous', 'system prompt', 'instrucciones anteriores', '</email>', '[/email]',
    ];

    /** @param  int  $delayMs  Espera media por llamada (para que latencias y tiempos parezcan reales). */
    public function __construct(private readonly int $delayMs = 0)
    {
    }

    public function name(): string
    {
        return 'simulated';
    }

    public function generate(LLMRequest $request): LLMResponse
    {
        $startedAt = hrtime(true);
        $this->wait();
        $properties = $request->jsonSchema['properties'] ?? [];
        $email = self::emailText($request);

        [$data, $toolCalls] = match (true) {
            isset($properties['intent']) => [self::classify($email), []],
            isset($properties['reply_body']) => self::agentStep($request, $email),
            default => throw new LLMInvalidResponseException('La IA simulada no sabe responder a esta petición.'),
        };

        $usage = new LLMUsage(
            inputTokens: self::tokens($request->system . json_encode($request->messages)) + 200,
            outputTokens: self::tokens((string) json_encode($data ?? $toolCalls)) + 150,
        );

        return new LLMResponse(
            provider: $this->name(),
            model: self::MODEL,
            text: $data === null ? null : (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            data: $data,
            usage: $usage,
            stopReason: $toolCalls === [] ? 'end_turn' : 'tool_use',
            latencyMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
            requestId: 'sim_' . Str::random(20),
            usageByModel: [['model' => self::MODEL, 'usage' => $usage]],
            toolCalls: $toolCalls,
            assistantContent: $toolCalls === [] ? null : ['simulated_tool_use' => array_map(fn (LLMToolCall $c) => $c->id, $toolCalls)],
        );
    }

    /** @return array<string, mixed> */
    public static function classify(string $email): array
    {
        $text = mb_strtolower($email);
        $injection = Str::contains($text, self::INJECTION);
        $intent = 'other';

        foreach (self::RULES as $candidate => $keywords) {
            if (Str::contains($text, $keywords)) {
                $intent = $candidate;
                break;
            }
        }

        $confidence = match (true) {
            $injection => 0.30,
            $intent === 'other' => 0.50,
            $intent === 'product_question' => 0.85,
            default => 0.92,
        };
        $needsHuman = $injection || in_array($intent, ['complaint', 'billing_issue', 'other'], true);
        preg_match_all('/#?\b(\d{5,6})\b/', $email, $orders);

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'language' => self::language($text),
            'summary' => '[IA simulada] ' . Str::limit(self::subject($email) ?: 'Email sin asunto', 120),
            'needs_human' => $needsHuman,
            'needs_human_reason' => $needsHuman ? '[IA simulada] Caso que requiere a una persona.' : null,
            'sentiment' => $intent === 'complaint' ? 'negative' : 'neutral',
            'urgency' => $intent === 'complaint' ? 'high' : 'normal',
            'injection_suspected' => $injection,
            'entities' => [
                'order_numbers' => array_values(array_unique($orders[1])),
                'invoice_numbers' => [],
                'customer_name' => null,
            ],
        ];
    }

    /** @return array{0: array<string, mixed>|null, 1: list<LLMToolCall>} */
    private static function agentStep(LLMRequest $request, string $email): array
    {
        $results = self::toolResults($request);
        $tools = array_column($request->tools, 'name');

        // Primer turno: pide una herramienta si tiene alguna que sirva.
        if ($results === null) {
            $order = self::classify($email)['entities']['order_numbers'][0] ?? null;

            $call = match (true) {
                $order !== null && in_array('get_order', $tools, true) => ['get_order', ['order_number' => $order]],
                in_array('search_company_documents', $tools, true) => ['search_company_documents', ['query' => Str::limit(self::subject($email) . ' ' . self::body($email), 300, ''), 'limit' => 3]],
                default => null,
            };

            if ($call !== null) {
                return [null, [new LLMToolCall('sim_tool_' . Str::random(12), $call[0], $call[1])]];
            }
        }

        return [self::reply($email, $results ?? []), []];
    }

    /** @param  list<LLMToolResult>  $results */
    private static function reply(string $email, array $results): array
    {
        $english = self::language(mb_strtolower($email)) === 'en';
        $sources = [];
        $facts = [];
        $failed = false;

        foreach ($results as $result) {
            $content = json_decode($result->content, true) ?? [];
            $failed = $failed || $result->isError;

            if (isset($content['order_number'])) {
                $sources[] = ['type' => 'order', 'reference' => (string) $content['order_number']];
                $facts[] = ($english ? 'Your order ' : 'Tu pedido ') . "#{$content['order_number']}: {$content['status_label']}.";
            }

            foreach ($content['results'] ?? [] as $hit) {
                $sources[] = ['type' => 'document', 'reference' => (string) $hit['source']];
                $facts[] = Str::limit((string) $hit['content'], 280);
            }
        }

        $confident = ! $failed && $facts !== [];
        $greeting = $english ? "Hello,\n\nThank you for writing to us." : "Hola:\n\nGracias por escribirnos.";
        $closing = $english ? "\n\nBest regards,\nCustomer support team" : "\n\nUn saludo,\nEquipo de atención al cliente";
        $body = $confident
            ? $greeting . "\n\n" . implode("\n\n", $facts)
            : $greeting . ($english ? "\n\nA member of our team will review your message shortly." : "\n\nUna persona del equipo revisará tu mensaje en breve.");

        return [
            'reply_body' => $body . $closing,
            'reply_language' => $english ? 'en' : 'es',
            'confidence' => $confident ? 0.9 : 0.5,
            'needs_human' => ! $confident,
            'needs_human_reason' => $confident ? null : '[IA simulada] No hay datos suficientes para responder.',
            'sources' => $sources,
            'pending_actions' => [],
            'internal_note' => '[IA simulada] Respuesta generada sin modelo real.',
        ];
    }

    /** @return list<LLMToolResult>|null Resultados del último turno, o null si aún no pidió herramientas. */
    private static function toolResults(LLMRequest $request): ?array
    {
        $last = $request->messages[array_key_last($request->messages)];

        return isset($last['tool_results']) ? $last['tool_results'] : null;
    }

    /** Texto del bloque <email> del primer mensaje (clasificación y agente lo incluyen). */
    private static function emailText(LLMRequest $request): string
    {
        $first = (string) ($request->messages[0]['content'] ?? '');

        return preg_match('~<email>(.*)</email>~s', $first, $m) ? $m[1] : $first;
    }

    private static function subject(string $email): string
    {
        return preg_match('/^Asunto:\s*(.*)$/mu', $email, $m) ? trim($m[1]) : '';
    }

    private static function body(string $email): string
    {
        $parts = preg_split('/\R\R/', $email, 2);

        return trim($parts[1] ?? $email);
    }

    private static function language(string $text): string
    {
        return preg_match('/\b(the|my|order|please|hello|thank you|do you)\b/', $text) ? 'en' : 'es';
    }

    private static function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /** Latencia aleatoria alrededor de delayMs (0 = sin espera, p. ej. en tests). */
    private function wait(): void
    {
        if ($this->delayMs > 0) {
            usleep(random_int((int) ($this->delayMs * 500), (int) ($this->delayMs * 1500)));
        }
    }
}
