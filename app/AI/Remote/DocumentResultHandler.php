<?php

namespace App\AI\Remote;

use App\AI\Remote\Data\StreamMessage;
use App\AI\Remote\Exceptions\InvalidEventException;
use App\AI\Remote\Exceptions\TenantMismatchException;
use App\Documents\Enums\DocumentStatus;
use App\Models\Document;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica los resultados del indexado de ai-service (`document.indexed.v1` y
 * `document.indexing_failed.v1`): el documento pasa a listo (con su nº de
 * fragmentos y modelo) o a error con el motivo para el panel. Las llamadas de
 * embeddings van a ai_requests como las demás.
 */
class DocumentResultHandler
{
    public const INDEXED = 'document.indexed';

    public const FAILED = 'document.indexing_failed';

    public function __construct(
        private readonly EventContract $contract,
        private readonly TenantService $tenants,
        private readonly RemoteCallRecorder $recorder,
        private readonly DocumentIndexPublisher $publisher,
    ) {
    }

    /** @return list<string> */
    public static function streams(): array
    {
        $prefix = config('ai.remote.stream_prefix');

        return ["{$prefix}" . self::INDEXED . '.v1', "{$prefix}" . self::FAILED . '.v1'];
    }

    public static function handles(string $stream): bool
    {
        return in_array($stream, self::streams(), true);
    }

    /**
     * @return string applied, stale o missing.
     *
     * @throws InvalidEventException contrato roto o empresa que no es la del documento.
     */
    public function handle(StreamMessage $message): string
    {
        $type = str_contains($message->stream, self::FAILED) ? self::FAILED : self::INDEXED;
        $event = $this->contract->validate("{$type}.v1", $message->payload());

        $document = Document::withoutGlobalScope(TenantScope::class)->with('tenant')->find($event['document_id']);

        if ($document === null) {
            // Se borró mientras se indexaba: que ai-service borre lo que acaba de guardar.
            if ($type === self::INDEXED) {
                $this->publisher->deleted((int) $event['tenant_id'], (int) $event['document_id']);
            }

            return 'missing';
        }

        if ($document->tenant_id !== $event['tenant_id']) {
            Log::critical('Resultado del indexado con una empresa que no es la del documento: descartado', [
                'document_id' => $document->id,
                'document_tenant_id' => $document->tenant_id,
                'event_tenant_id' => $event['tenant_id'],
            ]);

            throw new TenantMismatchException('La empresa del evento no coincide con la del documento.');
        }

        return $this->tenants->runAs($document->tenant, fn () => DB::transaction(function () use ($document, $event, $type) {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            $this->recorder->record(null, $event['usage']['calls'] ?? []);

            if ($document->status !== DocumentStatus::Processing) {
                return 'stale';
            }

            $type === self::INDEXED
                ? $document->markReady((int) $event['chunks_count'], (string) $event['embedding_model'])
                : $document->markFailed((string) $event['message']);

            Log::info('Resultado del indexado aplicado', ['document_id' => $document->id, 'status' => $document->status->value]);

            return 'applied';
        }));
    }
}
