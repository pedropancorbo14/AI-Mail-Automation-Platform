<?php

namespace App\Http\Controllers\Api\V1;

use App\Documents\DocumentService;
use App\Documents\Exceptions\DocumentProcessingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Security\Audit\AuditLogger;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Documentación de la empresa actual (base de conocimiento del RAG). El
 * route binding de {document} ya devuelve 404 para documentos de otra empresa.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Document::class);

        return DocumentResource::collection(Document::latest('id')->paginate(25));
    }

    /** Sube el documento; el indexado se hace en cola (status pending → ready). */
    public function store(StoreDocumentRequest $request): DocumentResource
    {
        $file = $request->file('file');

        try {
            $document = $this->documents->upload(
                (string) $file->get(),
                $file->getClientOriginalName(),
                $request->validated('title'),
                $request->user(),
                $file->getMimeType(),
            );
        } catch (DocumentProcessingException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $this->audit->record('document.uploaded', $document, $document->title, metadata: [
            'filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
        ]);

        return new DocumentResource($document->refresh());
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document);
    }

    /** Vuelve a extraer, trocear y calcular embeddings (p. ej. tras cambiar de modelo). */
    public function reindex(Document $document): DocumentResource
    {
        Gate::authorize('update', $document);

        $this->documents->reindex($document);
        $this->audit->record('document.reindexed', $document, $document->title);

        return new DocumentResource($document->refresh());
    }

    public function destroy(Document $document): Response
    {
        Gate::authorize('delete', $document);

        $this->audit->record('document.deleted', $document, $document->title, metadata: ['filename' => $document->original_filename]);
        $this->documents->delete($document);

        return response()->noContent();
    }
}
