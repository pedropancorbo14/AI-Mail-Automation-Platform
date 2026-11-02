<?php

namespace App\Tenancy\Signup;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alguien intentó registrarse con un email que ya tiene cuenta. La web
 * responde lo mismo que a un alta nueva (no revela qué emails existen) y el
 * aviso llega solo al dueño del email.
 */
class AccountExistsMessage extends Mailable
{
    use Queueable;

    public function __construct(public readonly User $user)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ya tienes una cuenta');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.account-exists', with: [
            'name' => $this->user->name,
            'url' => rtrim((string) config('app.url'), '/').'/login',
        ]);
    }
}
