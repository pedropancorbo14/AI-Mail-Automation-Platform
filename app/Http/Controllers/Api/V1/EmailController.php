<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\ListEmailsRequest;
use App\Http\Resources\EmailDetailResource;
use App\Http\Resources\EmailListResource;
use App\Mail\Enums\AttachmentStatus;
use App\Mail\Support\HtmlSanitizer;
use App\Models\Email;
use App\Models\EmailAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailController extends Controller
{
    /** Emails de la empresa, los más recientes primero, con filtros. */
    public function index(ListEmailsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Email::class);

        $query = Email::query()
            ->with('mailConnection:id,name,email')
            ->withCount('attachments')
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->validated('intent'), fn ($q, $intent) => $q->where('intent', $intent))
            ->when($request->validated('mailbox'), fn ($q, $mailbox) => $q->where('mail_connection_id', $mailbox))
            ->when($request->validated('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->validated('to'), fn ($q, $to) => $q->where('created_at', '<', now()->parse($to)->addDay()->startOfDay()))
            ->when($request->validated('q'), function ($q, string $text) {
                // % y _ del usuario son texto, no comodines de LIKE.
                $pattern = '%' . addcslashes($text, '%_\\') . '%';

                $q->whereAny(['subject', 'from_email', 'from_name'], 'ilike', $pattern);
            })
            ->latest('id');

        return EmailListResource::collection($query->paginate($request->integer('per_page', 25))->withQueryString());
    }

    /** Todo lo que pasó con un email: clasificación, propuesta, historial y traza del agente. */
    public function show(Email $email): EmailDetailResource
    {
        Gate::authorize('view', $email);

        $email->load([
            'mailConnection:id,name,email',
            'recipients',
            'attachments',
            'classificationRequest:id,model,cost_usd',
            'statusChanges.user:id,name',
            'replies.approvedBy:id,name',
            'agentRuns.aiRequests' => fn ($q) => $q->orderBy('id'),
            'agentRuns.aiRequests.toolCalls' => fn ($q) => $q->orderBy('id'),
        ])->loadCount('attachments')->loadSum('aiRequests', 'cost_usd');

        return new EmailDetailResource($email);
    }

    /**
     * HTML del email, saneado en el servidor. El panel lo muestra en un
     * iframe aislado; nunca se devuelve el HTML original.
     */
    public function html(Email $email, HtmlSanitizer $sanitizer): JsonResponse
    {
        Gate::authorize('view', $email);

        $html = $email->html_body ? $sanitizer->sanitize($email->html_body) : null;

        return response()->json(['data' => [
            'html' => $html,
            'remote_images' => $html !== null && HtmlSanitizer::hasRemoteImages($html),
        ]]);
    }

    /**
     * Descarga un adjunto. Siempre como descarga y como binario genérico:
     * un adjunto .html abierto en el navegador podría ejecutar código con
     * la sesión del panel.
     */
    public function attachment(Email $email, EmailAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $email);

        abort_unless($attachment->status === AttachmentStatus::Stored, 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->filename, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
