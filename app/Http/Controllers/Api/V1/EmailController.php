<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\ListEmailsRequest;
use App\Http\Resources\EmailListResource;
use App\Models\Email;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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
}
