<?php

namespace App\Documents;

use App\AI\Remote\DocumentIndexPublisher;
use App\Documents\Enums\DocumentStatus;
use App\Documents\Exceptions\DocumentProcessingException;
use App\Models\Document;
use App\Models\User;
use App\Tenancy\TenantService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ciclo de vida de los documentos de la base de conocimiento:
 * upload() guarda el original (disco privado) y pide el indexado a
 * ai-service (document.uploaded.v1: extrae, trocea y calcula los embeddings);
 * delete() borra fichero y documento y avisa a ai-service para que borre sus
 * fragmentos. Siempre dentro del tenant del documento.
 */
class DocumentService
{
    /** Extensiones admitidas → tipo (los navegadores mandan text/plain para .md). */
    public const array EXTENSIONS = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html',
    ];

    /** Tipos MIME admitidos (los de document.uploaded.v1) y sus alias. */
    private const array MIME_TYPES = [
        'application/pdf' => 'application/pdf',
        'text/plain' => 'text/plain',
        'text/markdown' => 'text/markdown',
        'text/x-markdown' => 'text/markdown',
        'text/html' => 'text/html',
    ];

    public function __construct(
        private readonly TenantService $tenants,
        private readonly DocumentIndexPublisher $publisher,
    ) {
    }

    /** Tipo MIME efectivo a partir del nombre (manda la extensión) o del tipo detectado. */
    public static function mimeFor(string $filename, ?string $detectedMime = null): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::EXTENSIONS[$extension] ?? self::MIME_TYPES[(string) $detectedMime] ?? null;
    }

    /**
     * @throws DocumentProcessingException tipo no soportado, demasiado grande o ya subido.
     */
    public function upload(string $content, string $filename, ?string $title = null, ?User $uploader = null, ?string $detectedMime = null): Document
    {
        $tenant = $this->tenants->currentOrFail();
        $mime = self::mimeFor($filename, $detectedMime)
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

        $this->publisher->upload($document);

        return $document;
    }

    /** Vuelve a indexar (p. ej. tras cambiar el modelo de embeddings). */
    public function reindex(Document $document): void
    {
        $document->forceFill(['status' => DocumentStatus::Pending, 'error' => null])->save();
        $this->publisher->upload($document);
    }

    public function delete(Document $document): void
    {
        $document->delete();
        Storage::disk($document->disk)->delete($document->path);
        $this->publisher->deleted($document->tenant_id, $document->id);
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
