<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only(['login_id', 'password']);

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'ログインIDまたはパスワードが正しくありません。',
                'errors' => ['login_id' => ['ログインIDまたはパスワードが正しくありません。']],
            ], 422);
        }

        $request->session()->regenerate();

        return $this->user($request);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user() === null ? null : [
                'id' => (string) $request->user()->getKey(),
                'loginId' => $request->user()->login_id,
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
