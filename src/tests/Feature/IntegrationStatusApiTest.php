<?php

namespace Tests\Feature;

use App\Exceptions\CrossWalkerException;
use App\Models\User;
use App\Services\CrossWalkerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://crosswalker.test/api/v1';

    private const API_KEY = 'secret-crosswalker-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.crosswalker.base_url' => self::BASE_URL,
            'services.crosswalker.api_key' => self::API_KEY,
        ]);
        Http::preventStrayRequests();
    }

    public function test_status_requires_authentication(): void
    {
        $this->getJson('/api/integrations/status')->assertUnauthorized();
    }

    public function test_status_is_empty_before_any_crosswalker_request(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/integrations/status')
            ->assertOk()
            ->assertExactJson(['data' => ['crossWalker' => null]]);
    }

    public function test_successful_search_is_recorded_with_its_time(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-19T09:15:00+09:00');
        Http::fake(['crosswalker.test/*' => Http::response(['data' => [], 'meta' => []])]);

        $this->getJson('/api/crosswalker/items')->assertOk();

        $this->getJson('/api/integrations/status')
            ->assertOk()
            ->assertExactJson(['data' => ['crossWalker' => [
                'status' => 'succeeded',
                'checkedAt' => '2026-10-19T00:15:00.000000Z',
                'message' => null,
            ]]]);
    }

    public function test_failed_lookup_is_recorded_with_a_displayable_summary_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-19T10:00:00+09:00');
        Http::fake(['crosswalker.test/*' => Http::response(['message' => 'invalid token '.self::API_KEY], 401)]);

        try {
            app(CrossWalkerClient::class)->lookupItems(['A-1001']);
            $this->fail('CrossWalkerException was not thrown.');
        } catch (CrossWalkerException) {
        }

        $response = $this->getJson('/api/integrations/status')
            ->assertOk()
            ->assertExactJson(['data' => ['crossWalker' => [
                'status' => 'failed',
                'checkedAt' => '2026-10-19T01:00:00.000000Z',
                'message' => 'CrossWalker APIのAPIキーが正しくありません。',
            ]]]);
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    public function test_connection_failure_does_not_store_the_request_url(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['crosswalker.test/*' => Http::failedConnection('cURL error 7: Failed to connect to '.self::BASE_URL.'/items?keyword=A-1001')]);

        $this->getJson('/api/crosswalker/items?keyword=A-1001')->assertStatus(502);

        $response = $this->getJson('/api/integrations/status')
            ->assertOk()
            ->assertJsonPath('data.crossWalker.status', 'failed')
            ->assertJsonPath('data.crossWalker.message', 'CrossWalker APIに接続できませんでした。時間をおいて再度お試しください。');
        $this->assertStringNotContainsString('crosswalker.test', $response->getContent());
    }

    public function test_missing_configuration_is_recorded_as_a_failure(): void
    {
        $this->actingAs(User::factory()->create());
        config(['services.crosswalker.api_key' => '']);

        $this->getJson('/api/crosswalker/items')->assertStatus(503);

        $this->getJson('/api/integrations/status')
            ->assertJsonPath('data.crossWalker.status', 'failed')
            ->assertJsonPath('data.crossWalker.message', 'CrossWalker APIの接続URLまたはAPIキーが設定されていません。');
    }

    public function test_only_the_latest_result_is_kept(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['crosswalker.test/*' => Http::sequence()
            ->push(['message' => 'server error'], 500)
            ->push(['data' => [], 'meta' => []])]);

        $this->getJson('/api/crosswalker/items')->assertStatus(503);
        $this->getJson('/api/integrations/status')->assertJsonPath('data.crossWalker.status', 'failed');

        $this->travel(5)->minutes();
        $this->getJson('/api/crosswalker/items')->assertOk();

        $this->getJson('/api/integrations/status')
            ->assertJsonPath('data.crossWalker.status', 'succeeded')
            ->assertJsonPath('data.crossWalker.message', null);
    }
}
