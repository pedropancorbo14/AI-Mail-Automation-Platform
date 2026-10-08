<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trozo de un documento con su embedding. Lo crea el procesado del
 * documento (App\Documents); el vector no se incluye en JSON (1024 números).
 *
 * @property list<float> $embedding
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['position', 'content', 'token_count', 'metadata', 'embedding'])]
#[Hidden(['embedding'])]
class DocumentChunk extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'token_count' => 'integer',
            'metadata' => 'array',
            'embedding' => AsVector::class,
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
