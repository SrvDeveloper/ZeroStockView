<?php

namespace Tests\Feature;

use App\Jobs\ProcessAmazonInventoryImport;
use App\Models\AmazonInventoryImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AmazonInventoryImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_queue_one_manual_import_and_retry_after_failure(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/amazon-inventory-imports')
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.triggerSource', 'manual');

        $import = AmazonInventoryImport::query()->sole();
        $this->assertSame(1, $import->active_key);
        Queue::assertPushed(ProcessAmazonInventoryImport::class, function (ProcessAmazonInventoryImport $job) use ($import): bool {
            return $job->importId === $import->id
                && $job->connection === 'amazon_inventory'
                && $job->queue === 'amazon-inventory';
        });

        $this->postJson('/api/amazon-inventory-imports')->assertConflict();
        $this->assertDatabaseCount('amazon_inventory_imports', 1);
        Queue::assertPushed(ProcessAmazonInventoryImport::class, 1);

        $job = new ProcessAmazonInventoryImport($import->id);
        $job->failed(null);
        $this->getJson('/api/amazon-inventory-imports/'.$import->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->postJson('/api/amazon-inventory-imports')->assertAccepted();
        $this->assertDatabaseCount('amazon_inventory_imports', 2);
        Queue::assertPushed(ProcessAmazonInventoryImport::class, 2);
    }

    public function test_manual_import_is_persisted_in_the_database_queue_without_running_it(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/amazon-inventory-imports')->assertAccepted();

        $this->assertDatabaseHas('amazon_inventory_imports', ['status' => 'queued', 'trigger_source' => 'manual']);
        $this->assertDatabaseHas('jobs', ['queue' => 'amazon-inventory', 'attempts' => 0]);
    }

    public function test_authenticated_user_can_read_success_and_failure_history(): void
    {
        $success = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'scheduled',
            'source' => 'sp_api',
            'completed_at' => now(),
            'own_retrieved_at' => now()->subMinute(),
            'fba_retrieved_at' => now(),
            'basis_at' => now(),
            'snapshot_date' => now()->toDateString(),
            'target_sku_count' => 1,
        ]);
        $success->snapshots()->create([
            'product_code' => 'P-1',
            'sku_code' => 'SKU-1',
            'asin' => 'B000000001',
            'amazon_own_stock' => 2,
            'amazon_fba_stock' => 3,
            'source' => 'sp_api',
            'basis_at' => now(),
        ]);
        $failed = AmazonInventoryImport::query()->create([
            'status' => 'failed',
            'trigger_source' => 'manual',
            'source' => 'sp_api',
            'completed_at' => now(),
            'error_message' => 'FBAレポートの生成に失敗しました。',
        ]);
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/amazon-inventory-imports')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $failed->id)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.errorMessage', 'FBAレポートの生成に失敗しました。')
            ->assertJsonPath('data.1.status', 'succeeded');
        $this->getJson('/api/amazon-inventory-imports/'.$success->id)
            ->assertOk()
            ->assertJsonPath('data.snapshots.0.skuCode', 'SKU-1')
            ->assertJsonPath('data.snapshots.0.amazonOwn', 2)
            ->assertJsonPath('data.snapshots.0.amazonFba', 3)
            ->assertJsonPath('data.snapshots.0.source', 'sp_api');
    }

    public function test_unauthenticated_user_cannot_read_import_history(): void
    {
        $import = AmazonInventoryImport::query()->create(['status' => 'failed', 'trigger_source' => 'manual', 'source' => 'sp_api']);

        $this->getJson('/api/amazon-inventory-imports')->assertUnauthorized();
        $this->getJson('/api/amazon-inventory-imports/'.$import->id)->assertUnauthorized();
        $this->postJson('/api/amazon-inventory-imports')->assertUnauthorized();
    }
}
