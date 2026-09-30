<?php

namespace App\Models;

use App\Mail\Enums\AttachmentStatus;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Adjunto de un email: metadatos + fichero en disco privado. status, disk,
 * path, sha256 y error no son asignables en masa (los pone la ingesta).
 * filename es el nombre que puso el remitente: solo para mostrar.
 *
 * @property AttachmentStatus $status
 */
#[Fillable(['provider_attachment_id', 'filename', 'content_type', 'size', 'inline', 'content_id'])]
#[Hidden(['disk', 'path'])]
class EmailAttachment extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'inline' => 'boolean',
            'status' => AttachmentStatus::class,
        ];
    }

    /** @return BelongsTo<Email, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function markStored(string $disk, string $path, string $sha256, int $size): void
    {
        $this->forceFill([
            'status' => AttachmentStatus::Stored,
            'disk' => $disk,
            'path' => $path,
            'sha256' => $sha256,
            'size' => $size,
            'error' => null,
        ])->save();
    }

    /** Skipped (a propósito, p. ej. demasiado grande) o Failed (el proveedor no lo entregó). */
    public function markNotStored(AttachmentStatus $status, string $reason): void
    {
        $this->forceFill(['status' => $status, 'error' => Str::limit($reason, 1000)])->save();
    }
}
