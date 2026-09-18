<?php

namespace App\Http\Requests;

use App\Models\ImportSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveImportSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $setting = $this->route('importSetting');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique(ImportSetting::class, 'name')->ignore($setting instanceof ImportSetting ? $setting->getKey() : null),
            ],
            'product_codes' => ['required', 'array', 'min:1', 'max:50'],
            'product_codes.*' => ['required', 'string', 'max:100', 'distinct'],
        ];
    }
}
