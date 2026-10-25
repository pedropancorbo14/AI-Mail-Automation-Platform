<?php

namespace App\Http\Resources;

use App\Mail\Credentials\ImapSmtpCredentials;
use App\Mail\Credentials\OAuthCredentials;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nunca incluye contraseñas ni tokens: solo la configuración no secreta,
 * para poder mostrarla y editarla.
 *
 * @mixin \App\Models\MailConnection
 */
class MailConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'provider' => $this->provider->value,
            'status' => $this->status->value,
            'settings' => $this->settings(),
            'save_sent_copy' => $this->save_sent_copy,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_error' => $this->last_error,
            // Correos que la sincronización apartó porque fallan siempre.
            'skipped_messages' => $this->skipped_messages_count ?? $this->skippedMessages()->count(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $credentials = $this->credentials;

        if ($credentials instanceof ImapSmtpCredentials) {
            return [
                'imap' => [
                    'host' => $credentials->imapHost,
                    'port' => $credentials->imapPort,
                    'encryption' => $credentials->imapEncryption->value,
                    'username' => $credentials->imapUsername,
                ],
                'smtp' => [
                    'host' => $credentials->smtpHost,
                    'port' => $credentials->smtpPort,
                    'encryption' => $credentials->smtpEncryption->value,
                    'username' => $credentials->smtpUsername,
                ],
            ];
        }

        if ($credentials instanceof OAuthCredentials) {
            return ['scopes' => $credentials->scopes];
        }

        return [];
    }
}
