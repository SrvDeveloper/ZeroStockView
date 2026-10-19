<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_only_admin_can_access_user_management_routes(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
        $this->postJson('/api/users', [])->assertUnauthorized();
        $this->putJson('/api/users/999999', [])->assertUnauthorized();
        $this->deleteJson('/api/users/999999')->assertUnauthorized();

        foreach ([User::ROLE_EDITOR, User::ROLE_VIEWER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/users')->assertForbidden();
            $this->postJson('/api/users', [])->assertForbidden();
            $this->putJson('/api/users/999999', [])->assertForbidden();
            $this->deleteJson('/api/users/999999')->assertForbidden();
        }
    }

    public function test_admin_can_create_list_edit_reset_password_and_delete_users(): void
    {
        $admin = User::factory()->create(['login_id' => 'admin']);
        $this->actingAs($admin);

        $created = $this->postJson('/api/users', [
            'login_id' => 'editor1',
            'password' => 'initialPass123',
            'password_confirmation' => 'initialPass123',
            'role' => User::ROLE_EDITOR,
        ])->assertCreated()->assertJsonPath('user.loginId', 'editor1')
            ->assertJsonPath('user.role', User::ROLE_EDITOR)
            ->assertJsonMissingPath('user.password');

        $user = User::query()->where('login_id', 'editor1')->firstOrFail();
        $this->assertTrue(Hash::check('initialPass123', $user->password));
        $this->getJson('/api/users')->assertOk()->assertJsonCount(2, 'users')
            ->assertJsonMissingPath('users.0.password')
            ->assertJsonMissingPath('users.1.password');

        $this->seedSession('unchanged-role-session', $user);
        $this->putJson('/api/users/'.$user->id, [
            'login_id' => 'editor2',
            'password' => '',
            'role' => User::ROLE_EDITOR,
        ])->assertOk()->assertJsonPath('user.loginId', 'editor2');
        $this->assertTrue(Hash::check('initialPass123', $user->fresh()->password));
        $this->assertDatabaseHas('sessions', ['id' => 'unchanged-role-session']);

        $this->putJson('/api/users/'.$user->id, [
            'login_id' => 'editor2',
            'password' => 'replacedPass123',
            'password_confirmation' => 'replacedPass123',
            'role' => User::ROLE_EDITOR,
        ])->assertOk()->assertJsonMissingPath('user.password');
        $this->assertTrue(Hash::check('replacedPass123', $user->fresh()->password));
        $this->assertDatabaseHas('sessions', ['id' => 'unchanged-role-session']);

        $this->deleteJson('/api/users/'.$user->id)->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_user_input_is_validated(): void
    {
        $admin = User::factory()->create(['login_id' => 'admin']);
        $user = User::factory()->create(['login_id' => 'existing']);
        $this->actingAs($admin);

        $this->postJson('/api/users', [
            'login_id' => 'existing',
            'password' => 'short',
            'password_confirmation' => 'different',
            'role' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors(['login_id', 'password', 'role']);

        $this->putJson('/api/users/'.$user->id, [
            'login_id' => '',
            'password' => '日本語password123',
            'password_confirmation' => '日本語password123',
            'role' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors(['login_id', 'password', 'role']);

        $this->assertSame('existing', $user->fresh()->login_id);
    }

    public function test_self_deletion_and_last_admin_demotions_are_rejected(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->deleteJson('/api/users/'.$admin->id)->assertUnprocessable();
        $this->putJson('/api/users/'.$admin->id, [
            'login_id' => $admin->login_id,
            'role' => User::ROLE_EDITOR,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => User::ROLE_ADMIN]);
    }

    public function test_role_change_invalidates_all_target_sessions_and_old_cookie_gets_401(): void
    {
        $admin = User::factory()->create(['login_id' => 'admin']);
        $target = User::factory()->create(['login_id' => 'target', 'role' => User::ROLE_EDITOR]);
        $this->postJson('/api/login', ['login_id' => 'target', 'password' => 'password'])->assertOk();
        $sessionId = DB::table('sessions')->where('user_id', $target->id)->value('id');
        $this->assertNotNull($sessionId);
        $this->seedSession('second-target-session', $target);
        $this->seedSession('admin-session', $admin);

        $this->actingAs($admin);
        $this->putJson('/api/users/'.$target->id, [
            'login_id' => $target->login_id,
            'role' => User::ROLE_VIEWER,
        ])->assertOk()->assertJsonPath('user.role', User::ROLE_VIEWER);

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'second-target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-session']);

        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/surveys')->assertUnauthorized();
    }

    public function test_self_role_change_ends_current_session(): void
    {
        $admin = User::factory()->create(['login_id' => 'admin']);
        User::factory()->create(['login_id' => 'other-admin']);
        $this->postJson('/api/login', ['login_id' => 'admin', 'password' => 'password'])->assertOk();
        $sessionId = DB::table('sessions')->where('user_id', $admin->id)->value('id');

        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->putJson('/api/users/'.$admin->id, [
                'login_id' => 'admin',
                'role' => User::ROLE_EDITOR,
            ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/surveys')->assertUnauthorized();
    }

    public function test_deleting_user_invalidates_all_sessions_and_last_admin_cannot_be_deleted(): void
    {
        $admin = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $target = User::factory()->create(['login_id' => 'target', 'role' => User::ROLE_VIEWER]);
        $this->postJson('/api/login', ['login_id' => 'target', 'password' => 'password'])->assertOk();
        $sessionId = DB::table('sessions')->where('user_id', $target->id)->value('id');
        $this->assertNotNull($sessionId);
        $this->seedSession('target-two', $target);
        $this->actingAs($admin);

        $this->deleteJson('/api/users/'.$target->id)->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-two']);

        $this->deleteJson('/api/users/'.$otherAdmin->id)->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => User::ROLE_ADMIN]);

        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/surveys')->assertUnauthorized();
    }

    private function seedSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
    }
}
