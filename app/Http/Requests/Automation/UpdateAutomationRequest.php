<?php

namespace App\Http\Requests\Automation;

use App\Automation\AutomationMode;
use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission(Permission::AUTOMATION_MANAGE);
    }

    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'required', Rule::enum(AutomationMode::class)],
            // [] = volver a las intenciones de la configuración de la plataforma.
            'auto_send_intents' => ['sometimes', 'array', 'max:20'],
            'auto_send_intents.*' => ['string', 'max:50'],
        ];
    }
}
