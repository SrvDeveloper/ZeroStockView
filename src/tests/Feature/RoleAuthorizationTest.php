<?php

namespace Tests\Feature;

use App\Models\ImportSetting;
use App\Models\Product;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE_MATRIX = [
        'GET api/inventory-trends/products' => 'role:admin,editor,viewer',
        'GET api/inventory-trends/export' => 'role:admin,editor,viewer',
        'GET api/inventory-trends' => 'role:admin,editor,viewer',
        'GET api/surveys' => 'role:admin,editor,viewer',
        'GET api/surveys/{survey}' => 'role:admin,editor,viewer',
        'GET api/surveys/{survey}/export' => 'role:admin,editor,viewer',
        'GET api/survey-files/{surveyFile}/download' => 'role:admin,editor,viewer',
        'GET api/surveys/{survey}/files/download' => 'role:admin,editor,viewer',
        'GET api/amazon-inventory-imports' => 'role:admin,editor',
        'GET api/amazon-inventory-imports/{amazonInventoryImport}' => 'role:admin,editor',
        'GET api/import-settings' => 'role:admin,editor',
        'POST api/import-settings' => 'role:admin,editor',
        'PUT api/import-settings/{importSetting}' => 'role:admin,editor',
        'GET api/crosswalker/items' => 'role:admin,editor',
        'GET api/integrations/status' => 'role:admin,editor',
        'POST api/surveys' => 'role:admin,editor',
        'POST api/surveys/{survey}/rerun' => 'role:admin,editor',
        'GET api/api-connection-settings' => 'role:admin',
        'PUT api/api-connection-settings' => 'role:admin',
        'POST api/api-connection-settings/rotate-key' => 'role:admin',
        'POST api/amazon-inventory-imports' => 'role:admin',
        'GET api/users' => 'role:admin',
        'POST api/users' => 'role:admin',
        'PUT api/users/{user}' => 'role:admin',
        'DELETE api/users/{user}' => 'role:admin',
        'DELETE api/import-settings/{importSetting}' => 'role:admin',
        'DELETE api/surveys/{survey}' => 'role:admin',
        'PUT api/surveys/{survey}/products/{surveyProduct}/memo' => 'role:admin',
        'PUT api/surveys/{survey}/skus/{surveySku}/memo' => 'role:admin',
        'DELETE api/survey-files/{surveyFile}' => 'role:admin',
        'DELETE api/surveys/{survey}/files' => 'role:admin',
    ];

    public function test_every_business_route_requires_exactly_one_role_middleware(): void
    {
        $routes = $this->businessRoutes();
        $this->assertCount(count(self::ROLE_MATRIX), $routes);

        foreach ($routes as $route) {
            $roles = $this->roleMiddleware($route);
            $key = $route->methods()[0].' '.$route->uri();
            $this->assertArrayHasKey($key, self::ROLE_MATRIX);
            $this->assertSame([self::ROLE_MATRIX[$key]], $roles, $key);
            $this->assertContains('auth', $route->gatherMiddleware(), $route->uri());
        }

        $publicApi = Route::getRoutes()->getByAction('App\Http\Controllers\Api\ExternalInventoryController@daily');
        $this->assertNotNull($publicApi);
        $this->assertContains('inventory.api', $publicApi->gatherMiddleware());
        $this->assertSame([], $this->roleMiddleware($publicApi));
    }

    public function test_forbidden_roles_are_rejected_before_model_binding_on_every_business_route(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $editor = User::factory()->create(['role' => User::ROLE_EDITOR]);

        foreach ($this->businessRoutes() as $route) {
            $key = $route->methods()[0].' '.$route->uri();
            $allowed = explode(',', substr(self::ROLE_MATRIX[$key], strlen('role:')));
            $path = '/'.preg_replace('/\{[^}]+\}/', '999999', $route->uri());

            foreach ([$viewer, $editor] as $user) {
                if (in_array($user->role, $allowed, true)) {
                    continue;
                }

                $this->actingAs($user);
                $this->call($route->methods()[0], $path, server: ['HTTP_ACCEPT' => 'application/json'])
                    ->assertForbidden();
            }
        }
    }

    public function test_guests_receive_401_and_unknown_roles_receive_403(): void
    {
        $this->getJson('/api/surveys')->assertUnauthorized();
        $this->getJson('/api/surveys/999999')->assertUnauthorized();
        $this->postJson('/api/surveys')->assertUnauthorized();
        $this->getJson('/api/api-connection-settings')->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => 'unknown']));
        $this->getJson('/api/surveys')->assertForbidden();
    }

    public function test_account_routes_remain_available_to_viewers_and_editors(): void
    {
        foreach ([User::ROLE_VIEWER, User::ROLE_EDITOR] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/user')->assertOk()->assertJsonPath('user.role', $role);
            $this->putJson('/api/password', [
                'current_password' => 'password',
                'password' => 'newpassword1234',
                'password_confirmation' => 'newpassword1234',
            ])->assertOk();
            $this->postJson('/api/logout')->assertOk();
        }
    }

    public function test_viewer_can_read_history_trends_and_download_results_and_source_files(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $survey = Survey::factory()->create();
        $file = SurveyFile::factory()->for($survey)->create();
        Storage::disk('local')->put($file->stored_path, "sku,quantity\nA-1001,1\n");
        Product::factory()->create(['product_code' => 'A-1001']);

        $this->getJson('/api/surveys')->assertOk();
        $this->getJson('/api/surveys/'.$survey->id)->assertOk();
        $this->getJson('/api/inventory-trends/products')->assertOk();
        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-10-01&to=2026-10-01&scope=free')->assertOk();
        $this->assertBinaryDownload('/api/surveys/'.$survey->id.'/export');
        $this->assertBinaryDownload('/api/inventory-trends/export?from=2026-10-01&to=2026-10-01');
        $this->get('/api/survey-files/'.$file->id.'/download')->assertOk();
        $this->assertBinaryDownload('/api/surveys/'.$survey->id.'/files/download');
    }

    public function test_editor_can_manage_groups_and_reach_import_and_rerun_validation(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        config(['services.crosswalker.base_url' => 'https://crosswalker.test/api/v1']);
        Http::fake(['*' => Http::response(['data' => [[
            'item_no' => 'A-1001',
            'brand' => 'ALPHA',
            'category' => 'アウター',
            'parent_asin' => '',
            'status' => 'active',
            'updated_at' => '',
            'skus' => [],
        ]]])]);

        $settingId = $this->postJson('/api/import-settings', [
            'name' => '編集者のグループ',
            'product_codes' => ['A-1001'],
        ])->assertCreated()->json('id');
        $this->putJson('/api/import-settings/'.$settingId, [
            'name' => '編集済みグループ',
            'product_codes' => ['A-1001'],
        ])->assertOk()->assertJsonPath('name', '編集済みグループ');
        $this->getJson('/api/import-settings')->assertOk();
        $this->getJson('/api/crosswalker/items?keyword=A-1001')->assertOk();
        $this->getJson('/api/integrations/status')->assertOk();
        $this->getJson('/api/amazon-inventory-imports')->assertOk();

        $this->postJson('/api/surveys', [])->assertUnprocessable();
        $this->postJson('/api/surveys/999999/rerun', [])->assertNotFound();
    }

    public function test_denied_mutations_leave_database_and_files_unchanged(): void
    {
        Storage::fake('local');
        $survey = Survey::factory()->create();
        $file = SurveyFile::factory()->for($survey)->create();
        Storage::disk('local')->put($file->stored_path, 'original');
        $product = SurveyProduct::factory()->for($survey)->create(['memo' => null]);
        $setting = ImportSetting::query()->create(['name' => '保護対象']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $this->deleteJson('/api/import-settings/'.$setting->id)->assertForbidden();
        $this->deleteJson('/api/surveys/'.$survey->id)->assertForbidden();
        $this->deleteJson('/api/survey-files/'.$file->id)->assertForbidden();
        $this->putJson('/api/surveys/'.$survey->id.'/products/'.$product->id.'/memo', ['memo' => '変更'])->assertForbidden();
        $this->postJson('/api/amazon-inventory-imports')->assertForbidden();

        $this->assertModelExists($setting);
        $this->assertModelExists($survey);
        $this->assertModelExists($file);
        $this->assertNull($product->fresh()->memo);
        $this->assertSame('original', Storage::disk('local')->get($file->stored_path));
        $this->assertDatabaseCount('amazon_inventory_imports', 0);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $this->postJson('/api/surveys', [])->assertForbidden();
        $this->postJson('/api/surveys/'.$survey->id.'/rerun', [])->assertForbidden();
        $this->assertModelExists($survey);
    }

    /** @return list<RoutingRoute> */
    private function businessRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            if (in_array($route->uri(), ['api/login', 'api/user', 'api/logout', 'api/password', 'api/v1/inventory/daily'], true)) {
                continue;
            }
            $routes[] = $route;
        }

        return $routes;
    }

    /** @return list<string> */
    private function roleMiddleware(RoutingRoute $route): array
    {
        return array_values(array_filter($route->gatherMiddleware(), fn (string $name): bool => str_starts_with($name, 'role:')));
    }

    private function assertBinaryDownload(string $path): void
    {
        $response = $this->get($path)->assertOk();
        $file = $response->baseResponse->getFile();
        $this->assertNotNull($file);
        if (is_file($file->getPathname())) {
            unlink($file->getPathname());
        }
    }
}
