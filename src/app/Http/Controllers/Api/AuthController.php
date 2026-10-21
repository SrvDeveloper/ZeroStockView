<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Services\LoginLockout;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginLockout $lockout): JsonResponse
    {
        $credentials = $request->safe()->only(['login_id', 'password']);

        // ロック中は、正しいパスワードでもログインさせない
        $lockedUntil = $lockout->lockedUntil($credentials['login_id']);
        if ($lockedUntil !== null) {
            return $this->lockedResponse($lockedUntil);
        }

        if (! Auth::attempt($credentials)) {
            $lockedUntil = $lockout->recordFailure($credentials['login_id']);
            if ($lockedUntil !== null) {
                Log::warning('ログインに続けて失敗したため、ログインIDをロックしました。', [
                    'login_id' => $credentials['login_id'],
                    'ip' => $request->ip(),
                    'locked_until' => $lockedUntil->toIso8601String(),
                ]);

                return $this->lockedResponse($lockedUntil);
            }

            return response()->json([
                'message' => '認証情報が正しくありません。',
                'errors' => ['login_id' => ['認証情報が正しくありません。']],
            ], 422);
        }

        $lockout->clear($credentials['login_id']);
        $request->session()->regenerate();

        return $this->user($request);
    }

    private function lockedResponse(CarbonImmutable $lockedUntil): JsonResponse
    {
        $message = 'ロックされています。'.$lockedUntil->setTimezone('Asia/Tokyo')->format('G:i').'以降に再度お試しいただくか、特権管理者に解除を依頼してください。';

        return response()->json([
            'message' => $message,
            'errors' => ['login_id' => [$message]],
            'lockedUntil' => $lockedUntil->toIso8601String(),
        ], 429);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user() === null ? null : [
                'id' => (string) $request->user()->getKey(),
                'loginId' => $request->user()->login_id,
                'role' => $request->user()->role,
            ],
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->password = $request->validated('password');
        $user->save();

        return response()->json(['message' => 'パスワードを変更しました。']);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'ログアウトしました。']);
    }
}
