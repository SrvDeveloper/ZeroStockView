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
            'amazon_own' => ['required', 'file', 'mimes:txt', 'max:20480'],
            'amazon_fba' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'boss' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'ec_stock' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'free_stock' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ];
    }
}
