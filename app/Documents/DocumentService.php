<?php

namespace App\Documents;

use App\AI\Embeddings\EmbeddingInputType;
use App\AI\Embeddings\EmbeddingService;
use App\AI\Exceptions\LLMException;
use App\Documents\Chunking\TextChunk;
use App\Documents\Chunking\TextChunker;
use App\Documents\Exceptions\DocumentProcessingException;
use App\Documents\Extraction\DocumentTextExtractor;
use App\Models\Document;
use App\Models\User;
use App\Tenancy\TenantService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ciclo de vida de los documentos de la base de conocimiento:
 * upload() guarda el original (disco privado) y encola el indexado;
 * process() extrae el texto, lo trocea, calcula los embeddings y guarda los
 * trozos; delete() borra fichero, trozos y documento. Siempre dentro del
 * tenant del documento.
 */
class DocumentService
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly DocumentTextExtractor $extractor,
        private readonly TextChunker $chunker,
        private readonly EmbeddingService $embeddings,
    ) {
    }

    /**
     * @throws DocumentProcessingException tipo no soportado, demasiado grande o ya subido.
     */
    public function upload(string $content, string $filename, ?string $title = null, ?User $uploader = null, ?string $detectedMime = null): Document
    {
        $tenant = $this->tenants->currentOrFail();
        $mime = DocumentTextExtractor::mimeFor($filename, $detectedMime)
            ?? throw new DocumentProcessingException('Formato no soportado. Se admiten PDF, TXT, Markdown y HTML.');

        $maxMb = (int) config('ai.rag.max_upload_mb');
        if (strlen($content) > $maxMb * 1024 * 1024) {
            throw new DocumentProcessingException("El documento supera el tamaño máximo ({$maxMb} MB).");
        }

        if (trim($content) === '') {
            throw new DocumentProcessingException('El documento está vacío.');
        }

        $sha256 = hash('sha256', $content);

        if (Document::where('sha256', $sha256)->exists()) {
            throw new DocumentProcessingException('Este documento ya está subido.');
        }

        // Ruta decidida por la app (nunca el nombre que trae el fichero).
        $disk = config('ai.rag.disk');
        $path = "tenants/{$tenant->id}/documents/" . Str::uuid();

        if (! Storage::disk($disk)->put($path, $content)) {
            throw new RuntimeException("No se pudo guardar el documento en el disco {$disk}.");
        }

        $document = new Document(['title' => self::title($title, $filename)]);
        $document->forceFill([
            'uploaded_by' => $uploader?->id,
            'original_filename' => Str::limit(self::clean($filename), 250, ''),
            'mime_type' => $mime,
            'size' => strlen($content),
            'disk' => $disk,
            'path' => $path,
            'sha256' => $sha256,
        ]);

        try {
            $document->save();
        } catch (UniqueConstraintViolationException) {
            Storage::disk($disk)->delete($path);

            throw new DocumentProcessingException('Este documento ya está subido.');
        }

        ProcessDocumentJob::dispatch($tenant->id, $document->id);

        return $document;
    }

    /**
     * Indexa (o reindexa) el documento. Errores del documento → failed (no
     * se reintenta); errores temporales del proveedor de embeddings → se
     * relanzan para que la cola reintente.
     *
     * @throws LLMException reintentable.
     */
    public function process(Document $document): void
    {
        $document->markProcessing();

        try {
            $content = Storage::disk($document->disk)->get($document->path)
                ?? throw new DocumentProcessingException('No se encuentra el fichero del documento.');

            $chunks = $this->chunker->chunk($this->extractor->extract($content, $document->mime_type));

            $vectors = $this->embeddings->embed(
                array_map(fn (TextChunk $chunk) => self::embeddingText($document, $chunk), $chunks),
                EmbeddingInputType::Document,
                EmbeddingService::PURPOSE_INDEXING,
            );
        } catch (DocumentProcessingException $e) {
            $this->failed($document, $e->getMessage());

            return;
        } catch (LLMException $e) {
            if ($e->retryable) {
                throw $e;
            }

            $this->failed($document, $e->getMessage());

            return;
        }

        // Reemplazo atómico: nunca queda un documento con trozos a medias.
        DB::transaction(function () use ($document, $chunks, $vectors) {
            $document->chunks()->delete();

            foreach ($chunks as $position => $chunk) {
                $document->chunks()->create([
                    'position' => $position,
                    'content' => $chunk->text,
                    'token_count' => $chunk->estimatedTokens,
                    'metadata' => $chunk->metadata ?: null,
                    'embedding' => $vectors->vectors[$position],
                ]);
            }

            $document->markReady(count($chunks), $vectors->model);
        });

        Log::info('Documento indexado', ['document_id' => $document->id, 'chunks' => count($chunks), 'model' => $vectors->model]);
    }

    public function delete(Document $document): void
    {
        $document->delete();   // los trozos se borran en cascada
        Storage::disk($document->disk)->delete($document->path);
    }

    /** Texto que se convierte en vector: el trozo con su contexto (título y sección). */
    public static function embeddingText(Document $document, TextChunk $chunk): string
    {
        $header = "Documento: {$document->title}";

        if (isset($chunk->metadata['heading'])) {
            $header .= "\nSección: {$chunk->metadata['heading']}";
        }

        return $header . "\n\n" . $chunk->text;
    }

    private function failed(Document $document, string $error): void
    {
        $document->markFailed($error);
        Log::warning('No se pudo indexar el documento', ['document_id' => $document->id, 'error' => $error]);
    }

    private static function title(?string $title, string $filename): string
    {
        $title = trim(self::clean((string) $title));

        return Str::limit($title !== '' ? $title : pathinfo(self::clean($filename), PATHINFO_FILENAME), 250, '');
    }

    private static function clean(string $value): string
    {
        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }
}
