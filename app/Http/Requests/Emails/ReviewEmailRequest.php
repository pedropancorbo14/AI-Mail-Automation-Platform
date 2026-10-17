<?php

namespace App\Http\Requests\Emails;

use App\Models\Email;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Decisiones de revisión humana: permiso emails.review sobre el email de la
 * ruta, comprobado antes que la validación. Sin datos (reprocesar) se usa tal
 * cual; las demás acciones la extienden con sus reglas.
 */
class ReviewEmailRequest extends FormRequest
{
    /** Límite del texto de una respuesta (unas 3.000 palabras). */
    public const int MAX_BODY_CHARS = 20000;

    public const int MAX_REASON_CHARS = 1000;

    public function authorize(): bool
    {
        $email = $this->route('email');

        return $email instanceof Email && $this->user()->can('review', $email);
    }

    public function rules(): array
    {
        return [];
    }
}
