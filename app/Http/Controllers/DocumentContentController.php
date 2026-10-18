<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Original de un documento para que ai-service lo indexe. Solo con la ruta
 * firmada que va en document.uploaded.v1 (caduca; middleware signed:relative):
 * sin sesión, sin token y sin poder elegir otro documento.
 */
class DocumentContentController extends Controller
{
    public function __invoke(int $document): StreamedResponse
    {
        // Consulta global deliberada: la firma ya identifica el documento.
        $model = Document::withoutGlobalScope(TenantScope::class)->findOrFail($document);
        abort_unless(Storage::disk($model->disk)->exists($model->path), 404);

        return Storage::disk($model->disk)->download($model->path, 'document', [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
