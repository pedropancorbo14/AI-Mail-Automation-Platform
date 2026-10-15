<?php

namespace App\Observability;

use LogicException;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis as RedisAdapter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Métricas acumuladas de la plataforma (contadores e histogramas) para
 * Prometheus. Todas se declaran en DEFINITIONS (nombre, ayuda, etiquetas y,
 * en los histogramas, sus intervalos): usar una métrica o etiqueta no
 * declarada es un error de programación (LogicException).
 *
 * Registrar una métrica NUNCA rompe el código de negocio: si el almacén
 * (Redis) falla, se deja un aviso en el log y se sigue.
 *
 * Las métricas de ESTADO ACTUAL (emails esperando a una persona, colas...)
 * no se guardan aquí: las calcula StateMetrics en cada lectura de /metrics.
 */
class Metrics
{
    /** Intervalos de duración en segundos (de 50 ms a 10 min). */
    private const array SECONDS = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600];

    /**
     * Etiquetas prohibidas: "job" e "instance" (las pone Prometheus a cada target).
     *
     * @var array<string, array{type: 'counter'|'histogram', help: string, labels: list<string>, buckets?: list<float>}>
     */
    public const array DEFINITIONS = [
        // --- Emails (Fases 4 y 9) ---
        'emails_ingested_total' => ['type' => 'counter', 'help' => 'Emails guardados en la ingesta (sin duplicados).', 'labels' => ['tenant', 'provider', 'automated']],
        'email_status_changes_total' => ['type' => 'counter', 'help' => 'Cambios de estado de los emails (a qué estado pasan).', 'labels' => ['tenant', 'to', 'by']],
        'email_processing_seconds' => ['type' => 'histogram', 'help' => 'Tiempo desde que se guarda un email hasta que se decide qué hacer con él.', 'labels' => ['tenant', 'outcome'], 'buckets' => self::SECONDS],
        'email_replies_sent_total' => ['type' => 'counter', 'help' => 'Respuestas enviadas al cliente.', 'labels' => ['tenant', 'automatic']],
        'email_replies_failed_total' => ['type' => 'counter', 'help' => 'Respuestas que no se pudieron enviar.', 'labels' => ['tenant']],
        'mail_syncs_total' => ['type' => 'counter', 'help' => 'Sincronizaciones de buzones (cada lote o fallo).', 'labels' => ['tenant', 'provider', 'result']],

        // --- IA (Fases 5 a 8) ---
        'ai_requests_total' => ['type' => 'counter', 'help' => 'Peticiones a la IA (LLM y embeddings).', 'labels' => ['tenant', 'provider', 'model', 'purpose', 'status']],
        'ai_tokens_total' => ['type' => 'counter', 'help' => 'Tokens facturados por la IA.', 'labels' => ['tenant', 'model', 'purpose', 'type']],
        'ai_cost_usd_total' => ['type' => 'counter', 'help' => 'Coste de la IA en dólares.', 'labels' => ['tenant', 'model', 'purpose']],
        'ai_request_duration_seconds' => ['type' => 'histogram', 'help' => 'Latencia de las peticiones a la IA.', 'labels' => ['provider', 'model', 'purpose'], 'buckets' => self::SECONDS],
        'ai_classifications_total' => ['type' => 'counter', 'help' => 'Emails clasificados por intención.', 'labels' => ['tenant', 'intent', 'needs_human']],
        'ai_classification_confidence' => ['type' => 'histogram', 'help' => 'Confianza de la clasificación.', 'labels' => ['intent'], 'buckets' => [0.3, 0.5, 0.6, 0.7, 0.75, 0.8, 0.85, 0.9, 0.95, 1]],
        'ai_tool_calls_total' => ['type' => 'counter', 'help' => 'Herramientas ejecutadas por la IA.', 'labels' => ['tenant', 'tool', 'status']],
        'ai_tool_call_duration_seconds' => ['type' => 'histogram', 'help' => 'Duración de las herramientas de la IA.', 'labels' => ['tool'], 'buckets' => self::SECONDS],
        'ai_agent_runs_total' => ['type' => 'counter', 'help' => 'Ejecuciones del agente por motivo de fin.', 'labels' => ['tenant', 'status', 'stop_reason']],
        'ai_agent_run_duration_seconds' => ['type' => 'histogram', 'help' => 'Duración de las ejecuciones del agente.', 'labels' => ['stop_reason'], 'buckets' => self::SECONDS],
        'ai_agent_replies_total' => ['type' => 'counter', 'help' => 'Propuestas de respuesta del agente y si necesitan una persona.', 'labels' => ['tenant', 'needs_human']],

        // --- Plataforma ---
        'http_requests_total' => ['type' => 'counter', 'help' => 'Peticiones HTTP atendidas.', 'labels' => ['method', 'route', 'status']],
        'http_request_duration_seconds' => ['type' => 'histogram', 'help' => 'Duración de las peticiones HTTP.', 'labels' => ['method', 'route'], 'buckets' => self::SECONDS],
        // "job_class" y no "job": Prometheus reserva "job" (nombre del target) y renombraría la nuestra.
        'jobs_processed_total' => ['type' => 'counter', 'help' => 'Trabajos de cola terminados (o fallidos definitivamente).', 'labels' => ['queue', 'job_class', 'result']],
        'job_duration_seconds' => ['type' => 'histogram', 'help' => 'Duración de los trabajos de cola.', 'labels' => ['queue', 'job_class'], 'buckets' => self::SECONDS],
    ];

    private ?CollectorRegistry $registry = null;

    /** @param  \Closure(): Adapter  $storage  Se crea al primer uso (sin conexión a Redis al arrancar). */
    public function __construct(
        private readonly \Closure $storage,
        private readonly string $namespace = 'aimail',
        private readonly bool $enabled = true,
    ) {
    }

    /** Almacén según config/metrics.php: Redis (compartido entre procesos) o memoria. */
    public static function fromConfig(): self
    {
        $config = config('metrics');

        return new self(
            storage: fn (): Adapter => match ($config['storage']) {
                'redis' => RedisAdapter::fromExistingConnection(self::redisConnection($config['redis'])),
                'memory' => new InMemory(),
                default => throw new LogicException("Almacén de métricas no soportado: {$config['storage']}."),
            },
            namespace: $config['namespace'],
            enabled: (bool) $config['enabled'],
        );
    }

    /** @param  array<string, string|int|bool|null>  $labels */
    public function increment(string $name, array $labels = [], float $by = 1): void
    {
        if ($by <= 0) {
            return;
        }

        $this->record($name, 'counter', $labels, fn (CollectorRegistry $r, array $def, array $values) => $r
            ->getOrRegisterCounter($this->namespace, $name, $def['help'], $def['labels'])
            ->incBy($by, $values));
    }

    /** @param  array<string, string|int|bool|null>  $labels */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $this->record($name, 'histogram', $labels, fn (CollectorRegistry $r, array $def, array $values) => $r
            ->getOrRegisterHistogram($this->namespace, $name, $def['help'], $def['labels'], $def['buckets'])
            ->observe($value, $values));
    }

    /** Texto en formato de exposición de Prometheus. */
    public function render(): string
    {
        if (! $this->enabled) {
            return '';
        }

        return (new RenderTextFormat())->render($this->registry()->getMetricFamilySamples());
    }

    /** Borra todo lo acumulado (tests y comando de mantenimiento). */
    public function wipe(): void
    {
        $this->registry()->wipeStorage();
    }

    /**
     * @param  array<string, string|int|bool|null>  $labels
     * @param  \Closure(CollectorRegistry, array, list<string>): void  $write
     */
    private function record(string $name, string $type, array $labels, \Closure $write): void
    {
        $definition = self::DEFINITIONS[$name] ?? throw new LogicException("Métrica no declarada: {$name}.");

        if ($definition['type'] !== $type) {
            throw new LogicException("La métrica {$name} es de tipo {$definition['type']}, no {$type}.");
        }

        $unknown = array_diff(array_keys($labels), $definition['labels']);

        if ($unknown !== []) {
            throw new LogicException("Etiquetas no declaradas en {$name}: " . implode(', ', $unknown) . '.');
        }

        if (! $this->enabled) {
            return;
        }

        $values = array_map(fn (string $label) => self::labelValue($labels[$label] ?? null), $definition['labels']);

        try {
            $write($this->registry(), $definition, $values);
        } catch (Throwable $e) {
            // Sin métricas se puede seguir trabajando; sin el email procesado, no.
            $this->registry = null; // reconecta en el siguiente intento
            Log::warning('No se pudo registrar una métrica', ['metric' => $name, 'detail' => $e->getMessage()]);
        }
    }

    private function registry(): CollectorRegistry
    {
        return $this->registry ??= new CollectorRegistry(($this->storage)(), registerDefaultMetrics: false);
    }

    private static function labelValue(string|int|bool|null $value): string
    {
        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /** @param  array{host: string, port: int, password: ?string, database: int, timeout: float}  $config */
    private static function redisConnection(array $config): \Redis
    {
        $redis = new \Redis();
        $redis->connect($config['host'], $config['port'], $config['timeout']);

        if (($config['password'] ?? '') !== '') {
            $redis->auth($config['password']);
        }

        $redis->select($config['database']);

        return $redis;
    }
}
