<?php

namespace App\Http\Requests\MailConnections;

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Enums\MailEncryption;
use App\Mail\Enums\MailProviderType;
use App\Mail\Security\AllowedMailHost;
use App\Models\MailConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Alta de una conexión IMAP/SMTP. Las de Microsoft y Google se crean con
 * su flujo OAuth (no hay contraseña que enviar).
 */
class StoreMailConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', MailConnection::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Única dentro de la empresa (el scope de tenant no aplica a Rule::unique).
                Rule::unique('mail_connections', 'email')->where('tenant_id', $this->user()->tenant_id),
            ],
            'provider' => ['required', Rule::in([MailProviderType::Imap->value])],
            'save_sent_copy' => ['sometimes', 'boolean'],
            ...self::serverRules('imap', required: true),
            ...self::serverRules('smtp', required: true),
        ];
    }

    public function messages(): array
    {
        return [
            'provider.in' => 'Por esta vía solo se pueden crear conexiones IMAP/SMTP; Microsoft y Google se conectan con OAuth.',
        ];
    }

    public function credentials(): ImapSmtpCredentials
    {
        return ImapSmtpCredentials::fromArray([
            'imap_host' => $this->validated('imap.host'),
            'imap_port' => $this->validated('imap.port'),
            'imap_encryption' => $this->validated('imap.encryption'),
            'imap_username' => $this->validated('imap.username'),
            'imap_password' => $this->validated('imap.password'),
            'smtp_host' => $this->validated('smtp.host'),
            'smtp_port' => $this->validated('smtp.port'),
            'smtp_encryption' => $this->validated('smtp.encryption'),
            'smtp_username' => $this->validated('smtp.username'),
            'smtp_password' => $this->validated('smtp.password'),
        ]);
    }

    /**
     * Reglas de un bloque imap.* / smtp.*. En edición todo es opcional y la
     * contraseña que no se envía se conserva.
     *
     * @return array<string, list<mixed>>
     */
    public static function serverRules(string $prefix, bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            $prefix => [$required ? 'required' : 'sometimes', 'array'],
            "{$prefix}.host" => [$presence, 'string', 'max:255', new AllowedMailHost()],
            "{$prefix}.port" => [$presence, 'integer', 'between:1,65535'],
            "{$prefix}.encryption" => [$presence, Rule::enum(MailEncryption::class)],
            "{$prefix}.username" => [$presence, 'string', 'max:255'],
            "{$prefix}.password" => [$presence, 'string', 'max:1024'],
        ];
    }
}
