<?php

namespace App\Http\Middleware;

use App\Models\ApiConnectionSetting;
use App\Services\IpNetworkMatcher;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateInventoryApi
{
    public function __construct(private readonly IpNetworkMatcher $ipNetworkMatcher) {}

    public function handle(Request $request, Closure $next): Response
    {
        $setting = ApiConnectionSetting::query()->first();
        if ($setting === null || ! $setting->enabled) {
            return new JsonResponse(['message' => '外部APIは現在無効です。'], 503);
        }

        $apiKey = trim((string) $request->header('X-API-Key'));
        if ($apiKey === '' || $setting->api_key_hash === null || ! hash_equals($setting->api_key_hash, hash('sha256', $apiKey))) {
            return new JsonResponse(['message' => 'APIキーが正しくありません。'], 401);
        }

        $allowedNetworks = collect($setting->allowed_networks ?? [])
            ->map(fn (mixed $entry): string => is_string($entry) ? $entry : (string) ($entry['network'] ?? ''))
            ->filter()
            ->values()
            ->all();
        $ipAddress = $request->ip();
        if ($ipAddress === null || ! $this->ipNetworkMatcher->matchesAny($ipAddress, $allowedNetworks)) {
            return new JsonResponse(['message' => 'このIPアドレスからの接続は許可されていません。'], 403);
        }

        return $next($request);
    }
}
