<?php

namespace App\Tenancy\Signup;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Enlace para confirmar el email de una cuenta nueva. */
class VerifyEmailMessage extends Mailable
{
    use Queueable;

    public function __construct(public readonly User $user, public readonly string $url)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirma tu email para empezar');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.verify-email', with: [
            'name' => $this->user->name,
            'url' => $this->url,
            'hours' => (int) config('plans.verification_hours'),
        ]);
    }
}
