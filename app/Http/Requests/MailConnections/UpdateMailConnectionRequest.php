<?php

namespace App\Http\Requests\MailConnections;

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Enums\MailConnectionStatus;
use App\Models\MailConnection;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateMailConnectionRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->connection());
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('mail_connections', 'email')
                    ->where('tenant_id', $this->connection()->tenant_id)
                    ->ignore($this->connection()),
            ],
            // Solo se puede desactivar o reactivar (reactivar = pendiente de probar).
            'enabled' => ['sometimes', 'boolean'],
        ];

        // Credenciales y copia en Enviados solo en IMAP: en OAuth se renuevan con
        // el flujo OAuth, y Microsoft y Gmail guardan la copia solos.
        if (! $this->connection()->provider->usesOAuth()) {
            $rules = [
                ...$rules,
                'save_sent_copy' => ['sometimes', 'boolean'],
                ...StoreMailConnectionRequest::serverRules('imap', required: false),
                ...StoreMailConnectionRequest::serverRules('smtp', required: false),
            ];
        }

        return $rules;
    }

    public function connection(): MailConnection
    {
        return $this->route('mailConnection');
    }

    public function changesCredentials(): bool
    {
        return $this->has('imap') || $this->has('smtp');
    }

    /** Credenciales actuales con los cambios enviados; lo no enviado (p. ej. la contraseña) se conserva. */
    public function credentials(ImapSmtpCredentials $current): ImapSmtpCredentials
    {
        $data = $current->toArray();

        foreach (['imap', 'smtp'] as $server) {
            foreach ($this->validated($server, []) as $field => $value) {
                $data["{$server}_{$field}"] = $value;
            }
        }

        return ImapSmtpCredentials::fromArray($data);
    }

    public function status(): ?MailConnectionStatus
    {
        if (! $this->has('enabled')) {
            return null;
        }

        return $this->boolean('enabled') ? MailConnectionStatus::Pending : MailConnectionStatus::Disabled;
    }
}
