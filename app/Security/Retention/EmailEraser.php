<?php

namespace App\Security\Retention;

use App\AI\Agent\Enums\EmailReplyStatus;
use App\Mail\Enums\EmailStatus;
use App\Models\AiAgentRun;
use App\Models\AiRequest;
use App\Models\AiToolCall;
use App\Models\Email;
use App\Models\EmailAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Borra un email y todo lo personal que cuelga de él:
 *
 *  - Filas: destinatarios, adjuntos, respuestas e historial de estados (cascade).
 *  - Ficheros de los adjuntos en disco (antes se quedaban: Fase 4).
 *  - Registros de IA: se conservan (tokens, costes, auditoría de qué hizo
 *    la IA) pero se vacía su contenido personal: respuesta de la IA,
 *    argumentos y resultados de herramientas, salida final del agente.
 *
 * Nunca borra un email que se está procesando o enviando. Dentro del tenant.
 */
class EmailEraser
{
    public function erase(Email $email): void
    {
        if (self::isBusy($email)) {
            throw new RuntimeException('El email se está procesando o enviando: no se puede borrar ahora.');
        }

        $files = $email->attachments()->whereNotNull('path')->get(['disk', 'path']);

        DB::transaction(function () use ($email) {
            AiRequest::where('email_id', $email->id)->update(['response' => null]);
            AiToolCall::where('email_id', $email->id)->update(['arguments' => '{}', 'result' => null]);
            AiAgentRun::where('email_id', $email->id)->update(['final_output' => null]);
            $email->delete();
        });

        // Después del commit: si la transacción fallara, los ficheros seguirían ahí.
        $files->each(fn (EmailAttachment $file) => Storage::disk($file->disk)->delete($file->path));
        $files->pluck('disk')->unique()->each(fn (string $disk) => Storage::disk($disk)->deleteDirectory("tenants/{$email->tenant_id}/emails/{$email->id}"));
    }

    public static function isBusy(Email $email): bool
    {
        return in_array($email->status, [EmailStatus::Processing, EmailStatus::Approved], true)
            || $email->replies()->where('status', EmailReplyStatus::Sending)->exists();
    }
}
