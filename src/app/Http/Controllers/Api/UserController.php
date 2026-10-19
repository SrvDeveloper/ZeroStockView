<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => User::query()->orderBy('login_id')->get()->map(fn (User $user): array => $this->present($user)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login_id' => ['required', 'string', 'max:100', Rule::unique(User::class, 'login_id')],
            'password' => ['required', 'string', 'min:12', 'regex:/^[\\x21-\\x7e]+$/', 'confirmed'],
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $user = User::query()->create($data);

        return response()->json(['user' => $this->present($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'login_id' => ['required', 'string', 'max:100', Rule::unique(User::class, 'login_id')->ignore($user->getKey())],
            'password' => ['nullable', 'string', 'min:12', 'regex:/^[\\x21-\\x7e]+$/', 'confirmed'],
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $roleChanged = false;
        $updated = DB::transaction(function () use ($user, $data, &$roleChanged): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $roleChanged = $locked->role !== $data['role'];
            if ($roleChanged && $locked->role === User::ROLE_ADMIN) {
                $this->ensureAnotherAdmin();
            }

            $locked->login_id = $data['login_id'];
            $locked->role = $data['role'];
            if (! empty($data['password'])) {
                $locked->password = $data['password'];
            }
            $locked->save();

            if ($roleChanged) {
                $this->invalidateSessions($locked);
            }

            return $locked;
        }, 3);

        if ($roleChanged && $updated->is($request->user())) {
            $this->logoutCurrentSession($request);
        }

        return response()->json(['user' => $this->present($updated)]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => ['自分自身は削除できません。']]);
        }

        DB::transaction(function () use ($user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if ($locked->role === User::ROLE_ADMIN) {
                $this->ensureAnotherAdmin();
            }

            $this->invalidateSessions($locked);
            $locked->delete();
        }, 3);

        return response()->json(status: 204);
    }

    private function ensureAnotherAdmin(): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->lockForUpdate()->pluck('id');
        if ($admins->count() <= 1) {
            throw ValidationException::withMessages(['role' => ['最後の特権管理者は変更・削除できません。']]);
        }
    }

    private function invalidateSessions(User $user): void
    {
        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }

    private function logoutCurrentSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function present(User $user): array
    {
        return [
            'id' => (string) $user->getKey(),
            'loginId' => $user->login_id,
            'role' => $user->role,
        ];
    }
}
