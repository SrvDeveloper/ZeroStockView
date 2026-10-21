<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LoginLockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ログインに続けて失敗したログインIDは一定時間ロックされ、時間が過ぎるか特権管理者が解除するとログインできる。
 */
class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-password-1234';

    private const LOCKED_MESSAGE = 'ロックされています。11:00以降に再度お試しいただくか、特権管理者に解除を依頼してください。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-21 10:00:00');
        User::factory()->create(['login_id' => 'editor', 'role' => User::ROLE_EDITOR, 'password' => Hash::make(self::PASSWORD)]);
    }

    public function test_login_id_is_locked_after_five_failures_and_unlocks_after_an_hour(): void
    {
        for ($attempt = 1; $attempt < LoginLockout::MAX_ATTEMPTS; $attempt++) {
            $this->login('editor', 'wrong-password')->assertUnprocessable()->assertJsonPath('message', '認証情報が正しくありません。');
        }
        $this->login('editor', 'wrong-password')
            ->assertTooManyRequests()
            ->assertJsonPath('message', self::LOCKED_MESSAGE)
            ->assertJsonPath('errors.login_id.0', self::LOCKED_MESSAGE);

        // ロック中は正しいパスワードでもログインできない
        $this->login('editor', self::PASSWORD)->assertTooManyRequests();
        $this->assertGuest();

        $this->travelTo('2026-10-21 10:59:59');
        $this->login('editor', self::PASSWORD)->assertTooManyRequests();
        $this->travelTo('2026-10-21 11:00:00');
        $this->login('editor', self::PASSWORD)->assertOk()->assertJsonPath('user.loginId', 'editor');
    }

    public function test_failures_are_counted_again_fifteen_minutes_after_the_first_failure(): void
    {
        for ($attempt = 1; $attempt < LoginLockout::MAX_ATTEMPTS; $attempt++) {
            $this->login('editor', 'wrong-password')->assertUnprocessable();
        }

        $this->travelTo('2026-10-21 10:15:00');
        for ($attempt = 1; $attempt < LoginLockout::MAX_ATTEMPTS; $attempt++) {
            $this->login('editor', 'wrong-password')->assertUnprocessable();
        }
        $this->login('editor', self::PASSWORD)->assertOk();
    }

    public function test_successful_login_resets_the_failure_count(): void
    {
        for ($attempt = 1; $attempt < LoginLockout::MAX_ATTEMPTS; $attempt++) {
            $this->login('editor', 'wrong-password')->assertUnprocessable();
        }
        $this->login('editor', self::PASSWORD)->assertOk();
        $this->postJson('/api/logout')->assertOk();

        for ($attempt = 1; $attempt < LoginLockout::MAX_ATTEMPTS; $attempt++) {
            $this->login('editor', 'wrong-password')->assertUnprocessable();
        }
        $this->login('editor', self::PASSWORD)->assertOk();
    }

    public function test_failures_are_counted_per_login_id_regardless_of_case_and_unknown_ids_lock_the_same_way(): void
    {
        User::factory()->create(['login_id' => 'viewer', 'role' => User::ROLE_VIEWER, 'password' => Hash::make(self::PASSWORD)]);
        foreach (['Editor', 'EDITOR', ' editor', 'editor', 'eDiToR'] as $loginId) {
            $this->login($loginId, 'wrong-password');
        }
        // 存在しないログインIDも、存在するときと同じ応答でロックされる
        for ($attempt = 1; $attempt <= LoginLockout::MAX_ATTEMPTS; $attempt++) {
            $unknown = $this->login('nobody', 'wrong-password');
        }
        $unknown->assertTooManyRequests()->assertJsonPath('message', self::LOCKED_MESSAGE);

        $this->login('editor', self::PASSWORD)->assertTooManyRequests();
        $this->login('viewer', self::PASSWORD)->assertOk();
    }

    public function test_admin_sees_the_lock_and_can_unlock_the_user(): void
    {
        $admin = User::factory()->create(['login_id' => 'admin', 'role' => User::ROLE_ADMIN]);
        for ($attempt = 1; $attempt <= LoginLockout::MAX_ATTEMPTS; $attempt++) {
            $this->login('editor', 'wrong-password');
        }
        $editor = User::query()->where('login_id', 'editor')->sole();

        $this->actingAs($admin);
        $this->getJson('/api/users')->assertOk()
            ->assertJsonPath('users.0.loginId', 'admin')
            ->assertJsonPath('users.0.lockedUntil', null)
            ->assertJsonPath('users.1.loginId', 'editor')
            ->assertJsonPath('users.1.lockedUntil', '2026-10-21T11:00:00+09:00');
        $this->postJson("/api/users/{$editor->id}/unlock")->assertOk()
            ->assertJsonPath('user.loginId', 'editor')
            ->assertJsonPath('user.lockedUntil', null);
        $this->postJson('/api/logout')->assertOk();

        $this->login('editor', self::PASSWORD)->assertOk();
    }

    public function test_only_admin_can_unlock_users(): void
    {
        $editor = User::query()->where('login_id', 'editor')->sole();
        $this->postJson("/api/users/{$editor->id}/unlock")->assertUnauthorized();

        foreach ([User::ROLE_EDITOR, User::ROLE_VIEWER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->postJson("/api/users/{$editor->id}/unlock")->assertForbidden();
        }
    }

    private function login(string $loginId, string $password): TestResponse
    {
        return $this->postJson('/api/login', ['login_id' => $loginId, 'password' => $password]);
    }
}
