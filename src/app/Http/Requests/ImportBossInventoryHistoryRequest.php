<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** BOSS の在庫変動履歴のアップロード（確認・適用で共通。docs/BOSS在庫の休日補完 BHR-04） */
class ImportBossInventoryHistoryRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['file' => '在庫変動履歴'];
    }
}
