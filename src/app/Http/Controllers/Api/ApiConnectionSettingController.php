<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiConnectionSetting;
use App\Services\IpNetworkMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiConnectionSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->serialize(ApiConnectionSetting::query()->first()));
    }

    public function update(Request $request, IpNetworkMatcher $ipNetworkMatcher): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'allowed_networks' => ['present', 'array', 'max:50'],
            'allowed_networks.*.network' => [
                'required',
                'string',
                'max:64',
                'distinct',
                function (string $attribute, mixed $value, \Closure $fail) use ($ipNetworkMatcher): void {
                    if (! is_string($value) || ! $ipNetworkMatcher->isValid($value)) {
                        $fail('許可IPアドレス / CIDRの形式が正しくありません。');
                    }
                },
            ],
            'allowed_networks.*.memo' => ['nullable', 'string', 'max:100'],
        ]);

        $setting = ApiConnectionSetting::query()->firstOrNew();
        if ($data['enabled'] && ! $setting->api_key_hash) {
            throw ValidationException::withMessages([
                'enabled' => ['外部APIを有効にする前にAPIキーを発行してください。'],
            ]);
        }
        if ($data['enabled'] && $data['allowed_networks'] === []) {
            throw ValidationException::withMessages([
                'allowed_networks' => ['外部APIを有効にする場合は、許可IPアドレスまたはCIDRを1件以上登録してください。'],
            ]);
        }

        $setting->enabled = $data['enabled'];
        $setting->allowed_networks = array_values(array_map(fn (array $entry): array => [
            'network' => $entry['network'],
            'memo' => $entry['memo'] ?? '',
        ], $data['allowed_networks']));
        $setting->save();

        return response()->json($this->serialize($setting));
    }

    public function rotateKey(): JsonResponse
    {
        $setting = ApiConnectionSetting::query()->firstOrNew();
        $apiKey = 'zsv_live_'.Str::random(40);

        $setting->api_key_hash = hash('sha256', $apiKey);
        $setting->api_key_suffix = substr($apiKey, -4);
        $setting->api_key_issued_at = now();
        $setting->enabled ??= false;
        $setting->allowed_networks ??= [];
        $setting->save();

        return response()->json([
            ...$this->serialize($setting),
            'apiKey' => $apiKey,
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(?ApiConnectionSetting $setting): array
    {
        $allowedNetworks = collect($setting?->allowed_networks ?? [])
            ->map(fn (mixed $entry): array => is_string($entry)
                ? ['network' => $entry, 'memo' => '']
                : ['network' => (string) ($entry['network'] ?? ''), 'memo' => (string) ($entry['memo'] ?? '')])
            ->filter(fn (array $entry): bool => $entry['network'] !== '')
            ->values()
            ->all();

        return [
            'enabled' => (bool) ($setting?->enabled ?? false),
            'apiKeyConfigured' => $setting?->api_key_hash !== null,
            'apiKeyMasked' => $setting?->api_key_suffix ? 'zsv_live_••••••••••••'.$setting->api_key_suffix : null,
            'apiKeyIssuedAt' => $setting?->api_key_issued_at?->toISOString(),
            'allowedNetworks' => $allowedNetworks,
        ];
    }
}
