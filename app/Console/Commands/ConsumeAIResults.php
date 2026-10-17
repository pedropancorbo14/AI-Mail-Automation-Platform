<?php

namespace App\Console\Commands;

use App\AI\Remote\Contracts\EventStream;
use App\AI\Remote\Data\StreamMessage;
use App\AI\Remote\Exceptions\InvalidEventException;
use App\AI\Remote\Exceptions\TenantMismatchException;
use App\AI\Remote\RemoteResultHandler;
use App\Observability\Metrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consumidor de los resultados de ai-service (proceso largo, servicio
 * "ai-results" del compose). Lee email.processed.v1 y
 * email.processing_failed.v1 con el consumer group de Laravel y aplica cada
 * uno con RemoteResultHandler.
 *
 *  - Aplicado, duplicado o email borrado → se confirma (XACK + XDEL).
 *  - No cumple el contrato o empresa que no coincide → cola de errores.
 *  - Error inesperado (BD caída...) → sin confirmar: se reclama pasado
 *    claim_idle_ms; tras max_deliveries, a la cola de errores.
 */
class ConsumeAIResults extends Command
{
    protected $signature = 'ai:consume-results {--once : Procesa lo que haya y termina (pruebas)}';

    protected $description = 'Aplica los resultados del servicio de IA (Redis Streams)';

    private bool $stop = false;

    public function handle(EventStream $streams, RemoteResultHandler $handler, Metrics $metrics): int
    {
        $group = (string) config('ai.remote.consumer_group');
        $consumer = (string) config('ai.remote.consumer_name');
        $names = RemoteResultHandler::streams();

        foreach ($names as $stream) {
            $streams->ensureGroup($stream, $group);
        }

        $this->trap([SIGTERM, SIGINT], fn () => $this->stop = true);
        $this->info('Esperando resultados del servicio de IA en ' . implode(', ', $names));
        $lastTrim = 0;

        do {
            $batch = [];
            foreach ($names as $stream) {
                $batch = [...$batch, ...$streams->claimAbandoned($stream, $group, $consumer, (int) config('ai.remote.claim_idle_ms'), 10)];
            }
            $batch = [...$batch, ...$streams->read($names, $group, $consumer, 10, $this->option('once') ? 1 : (int) config('ai.remote.block_ms'))];

            foreach ($batch as $message) {
                $this->process($message, $streams, $handler, $metrics, $group);
            }

            if (time() - $lastTrim >= 60) {
                $lastTrim = time();
                $olderThan = (int) ((microtime(true) - (int) config('ai.remote.retention_hours') * 3600) * 1000);
                foreach ($names as $stream) {
                    $streams->trim($stream, $olderThan);
                    $streams->trim("{$stream}.dlq", $olderThan);
                }
            }
        } while (! $this->stop && ! $this->option('once'));

        return self::SUCCESS;
    }

    private function process(StreamMessage $message, EventStream $streams, RemoteResultHandler $handler, Metrics $metrics, string $group): void
    {
        $event = str_contains($message->stream, RemoteResultHandler::FAILED) ? 'processing_failed' : 'processed';

        try {
            $result = $handler->handle($message);
            $streams->ack($message, $group);
        } catch (InvalidEventException $e) {
            $result = $e instanceof TenantMismatchException ? 'tenant_mismatch' : 'invalid';
            $streams->deadLetter($message, $group, $e->getMessage());
            Log::warning('Resultado de IA a la cola de errores', ['stream' => $message->stream, 'id' => $message->id, 'reason' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Error al aplicar un resultado de IA', ['stream' => $message->stream, 'id' => $message->id, 'exception' => $e::class, 'detail' => $e->getMessage()]);

            if ($message->deliveries >= (int) config('ai.remote.max_deliveries')) {
                $streams->deadLetter($message, $group, 'Error al aplicarlo tras varios intentos: ' . $e->getMessage());
                $result = 'dead_lettered';
            } else {
                $result = 'retry';
            }
        } finally {
            Context::forget('trace_id');
        }

        $metrics->increment('ai_remote_results_total', ['event' => $event, 'result' => $result]);
        $this->line(sprintf('%s %s → %s', $message->stream, $message->id, $result));
    }
}
