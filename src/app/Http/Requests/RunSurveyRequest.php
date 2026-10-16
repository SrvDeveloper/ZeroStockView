<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RunSurveyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'import_setting_id' => ['sometimes', 'integer', 'exists:import_settings,id'],
            'amazon_own' => ['prohibited'],
            'amazon_fba' => ['prohibited'],
            'boss' => ['prohibited'],
            'ec_stock' => ['prohibited'],
            'free_stock' => ['prohibited'],
            'files' => ['required', 'array', 'min:1', 'max:3'],
            'files.*' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ];
    }
}
