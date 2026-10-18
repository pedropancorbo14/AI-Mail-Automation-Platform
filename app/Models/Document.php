<?php

namespace App\Models;

use App\Documents\Enums\DocumentStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Documento de la base de conocimiento de una empresa: el fichero original y
 * su estado. Los fragmentos y sus embeddings los guarda ai-service (que lo
 * indexa). Solo el título es asignable en masa: fichero, estado e indexación
 * los gestionan App\Documents\DocumentService y los resultados de ai-service.
 *
 * @property DocumentStatus $status
 */
#[Fillable(['title'])]
#[Hidden(['disk', 'path'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'chunks_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'size' => 'integer',
            'chunks_count' => 'integer',
            'processed_at' => 'datetime',
            'index_requested_at' => 'datetime',
            'index_attempts' => 'integer',
        ];
    }

    public function markProcessing(): void
    {
        $this->forceFill(['status' => DocumentStatus::Processing, 'error' => null])->save();
    }

    public function markReady(int $chunks, string $embeddingModel): void
    {
        $this->forceFill([
            'status' => DocumentStatus::Ready,
            'error' => null,
            'chunks_count' => $chunks,
            'embedding_model' => $embeddingModel,
            'processed_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => DocumentStatus::Failed, 'error' => Str::limit($error, 1000)])->save();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
