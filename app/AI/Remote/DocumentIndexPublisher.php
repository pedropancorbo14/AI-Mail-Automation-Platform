<?php

namespace App\AI\Remote;

use App\AI\Remote\Contracts\EventStream;
use App\Documents\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pide a ai-service que indexe o borre un documento (el indexado es suyo:
 * extracción, troceado, embeddings y fragmentos). Laravel guarda el fichero y
 * el estado; ai-service descarga el original por una ruta firmada que caduca.
 * Se publica después del commit; si Redis falla, el reconciliador reintenta.
 */
class DocumentIndexPublisher
{
    public const UPLOADED = 'document.uploaded';

    public const DELETED = 'document.deleted';

    public function __construct(
        private readonly EventStream $streams,
        private readonly EventContract $contract,
    ) {
    }

    /** Indexar (o reindexar): el documento pasa a "Indexando" hasta que ai-service responda. */
    public function upload(Document $document): void
    {
        $event = $this->contract->validate(self::UPLOADED . '.v1', $this->envelope(self::UPLOADED, $document) + [
            'title' => Str::limit($document->title, 250, ''),
            'mime_type' => $document->mime_type,
            'size' => (int) $document->size,
            'sha256' => $document->sha256,
            // Ruta relativa: ai-service la une a su URL de Laravel (el evento nunca elige el host).
            'download_path' => URL::temporarySignedRoute(
                'internal.documents.content',
                now()->addMinutes((int) config('ai.remote.document_link_minutes')),
                ['document' => $document->id],
                absolute: false,
            ),
        ]);

        $document->forceFill([
            'status' => DocumentStatus::Processing,
            'error' => null,
            'index_event_id' => $event['event_id'],
            'index_requested_at' => now(),
            'index_attempts' => $document->index_attempts + 1,
        ])->save();

        $this->publishAfterCommit(self::UPLOADED, $event, $document->id);
    }

    /** Borrar sus fragmentos (documento borrado o reindexado de un documento que ya no existe). */
    public function deleted(int $tenantId, int $documentId): void
    {
        $event = $this->contract->validate(self::DELETED . '.v1', [
            'event_id' => (string) Str::uuid(),
            'event_type' => self::DELETED,
            'event_version' => 1,
            'occurred_at' => now()->toIso8601ZuluString('microsecond'),
            'trace_id' => EmailEventPublisher::traceId(),
            'tenant_id' => $tenantId,
            'document_id' => $documentId,
        ]);

        $this->publishAfterCommit(self::DELETED, $event, $documentId);
    }

    /** @return array<string, mixed> */
    private function envelope(string $type, Document $document): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'event_version' => 1,
            'occurred_at' => now()->toIso8601ZuluString('microsecond'),
            'trace_id' => EmailEventPublisher::traceId(),
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
        ];
    }

    /** @param  array<string, mixed>  $event */
    private function publishAfterCommit(string $type, array $event, int $documentId): void
    {
        DB::afterCommit(function () use ($type, $event, $documentId) {
            try {
                $this->streams->publish(config('ai.remote.stream_prefix') . "{$type}.v1", [
                    'event_id' => $event['event_id'],
                    'event_type' => $type,
                    'payload' => (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable $e) {
                Log::warning('No se pudo publicar el documento para el servicio de IA', [
                    'document_id' => $documentId,
                    'event_type' => $type,
                    'exception' => $e::class,
                    'detail' => $e->getMessage(),
                ]);
            }
        });
    }
}
