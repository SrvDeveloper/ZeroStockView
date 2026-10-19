<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [
            'admin' => [User::ROLE_ADMIN],
            'editor' => [User::ROLE_EDITOR],
            'viewer' => [User::ROLE_VIEWER],
        ];
    }

    #[DataProvider('roles')]
    public function test_login_and_current_user_return_the_saved_role_without_secrets(string $role): void
    {
        $user = User::factory()->create([
            'login_id' => 'role-test',
            'password' => 'password1234',
            'role' => $role,
        ]);
        $expected = ['user' => [
            'id' => (string) $user->id,
            'loginId' => 'role-test',
            'role' => $role,
        ]];

        $this->postJson('/api/login', [
            'login_id' => 'role-test',
            'password' => 'password1234',
        ])->assertOk()->assertExactJson($expected);

        $this->getJson('/api/user')->assertOk()->assertExactJson($expected);
    }

    public function test_migration_preserves_existing_user_and_assigns_admin_role(): void
    {
        $legacy = User::factory()->create();
        $migration = require database_path('migrations/2026_10_19_000000_add_role_to_users_table.php');

        $migration->down();
        $migration->up();

        $this->assertSame(User::ROLE_ADMIN, $legacy->fresh()->role);
        $this->assertTrue(Hash::check('password', $legacy->fresh()->password));
        $this->postJson('/api/login', [
            'login_id' => $legacy->login_id,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', User::ROLE_ADMIN);
    }

    public function test_initial_seed_user_is_admin_and_unknown_roles_never_match(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('login_id', 'admin')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue($admin->hasAnyRole(User::ROLE_ADMIN));
        $this->assertFalse($admin->hasAnyRole(User::ROLE_EDITOR));
        $this->assertFalse(User::factory()->make(['role' => 'unknown'])->hasAnyRole('unknown', User::ROLE_ADMIN));
    }

    public function test_new_user_cannot_be_created_without_an_explicit_role(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'login_id' => 'missing-role',
            'password' => 'unused',
        ]);
    }
}
