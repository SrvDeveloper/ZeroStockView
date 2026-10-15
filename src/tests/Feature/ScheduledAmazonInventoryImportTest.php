<?php

namespace Tests\Feature;

use App\Jobs\ProcessAmazonInventoryImport;
use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Services\AmazonInventoryImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ScheduledAmazonInventoryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_runs_at_nine_in_tokyo(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'amazon-inventory:queue'));

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $event->timezone);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 08:59:00', 'Asia/Tokyo'));
        $this->assertFalse($event->isDue($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Tokyo'));
        $this->assertTrue($event->isDue($this->app));
    }

    public function test_scheduler_retries_pending_survey_reflection_every_five_minutes(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'survey:amazon-reconcile'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:05:00', 'Asia/Tokyo'));
        $this->assertTrue($event->isDue($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:06:00', 'Asia/Tokyo'));
        $this->assertFalse($event->isDue($this->app));
    }

    public function test_reconcile_command_retries_today_and_accepts_a_past_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Tokyo'));
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'snapshot_date' => '2026-10-15',
            'basis_at' => now(),
            'completed_at' => now(),
        ]);
        $import->snapshots()->create([
            'product_code' => 'P-1',
            'sku_code' => 'SKU-1',
            'asin' => 'B000000001',
            'amazon_own_stock' => 4,
            'amazon_fba_stock' => 5,
            'basis_at' => now(),
        ]);
        $survey = Survey::factory()->spApi()->create(['executed_at' => '2026-10-15 08:00:00']);
        $product = SurveyProduct::factory()->for($survey)->create();
        $sku = SurveySku::factory()->for($product, 'product')->pendingAmazon()->create(['sku_code' => 'SKU-1']);

        $this->artisan('survey:amazon-reconcile')->assertSuccessful();
        $this->assertSame($import->id, $survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(4, $sku->fresh()->amazon_own_stock);
        $this->artisan('survey:amazon-reconcile')->assertSuccessful();
        $this->artisan('survey:amazon-reconcile', ['date' => '2026-10-15'])->assertSuccessful();
        $this->artisan('survey:amazon-reconcile', ['date' => '2026-02-30'])->assertFailed();
    }

    public function test_scheduler_starts_a_short_lived_worker_only_when_jobs_are_queued(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'queue:work amazon_inventory'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertStringContainsString('--stop-when-empty --max-jobs=1', $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertFalse($event->filtersPass($this->app));

        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Tokyo'));
        $this->assertTrue($event->isDue($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:01:00', 'Asia/Tokyo'));
        $this->assertFalse($event->isDue($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:05:00', 'Asia/Tokyo'));
        $this->artisan('amazon-inventory:queue')->assertSuccessful();

        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_scheduled_command_queues_once_per_tokyo_day_and_uses_the_dedicated_queue(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Tokyo'));

        $this->artisan('amazon-inventory:queue')->assertSuccessful();
        $import = AmazonInventoryImport::query()->sole();

        $this->assertSame('scheduled', $import->trigger_source);
        $this->assertSame('queued', $import->status);
        Queue::assertPushed(ProcessAmazonInventoryImport::class, fn (ProcessAmazonInventoryImport $job): bool => $job->importId === $import->id
            && $job->connection === 'amazon_inventory'
            && $job->queue === 'amazon-inventory'
        );

        (new ProcessAmazonInventoryImport($import->id))->failed(null);
        $this->artisan('amazon-inventory:queue')->assertSuccessful();
        $this->assertDatabaseCount('amazon_inventory_imports', 1);
        Queue::assertPushed(ProcessAmazonInventoryImport::class, 1);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'Asia/Tokyo'));
        $this->artisan('amazon-inventory:queue')->assertSuccessful();
        $this->assertDatabaseCount('amazon_inventory_imports', 2);
        Queue::assertPushed(ProcessAmazonInventoryImport::class, 2);
    }

    public function test_scheduled_command_does_not_queue_while_manual_import_is_active(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Tokyo'));
        app(AmazonInventoryImportService::class)->queue('manual');

        $this->artisan('amazon-inventory:queue')->assertSuccessful();

        $this->assertDatabaseCount('amazon_inventory_imports', 1);
        Queue::assertNotPushed(ProcessAmazonInventoryImport::class);
    }

    public function test_stale_queued_import_is_marked_failed_without_a_worker(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'Asia/Tokyo'));
        $import = app(AmazonInventoryImportService::class)->queue('scheduled');

        $this->travelTo(CarbonImmutable::parse('2026-10-14 11:05:00', 'Asia/Tokyo'));
        app(AmazonInventoryImportService::class)->expireStale();

        $this->assertDatabaseHas('amazon_inventory_imports', [
            'id' => $import->id,
            'status' => 'failed',
            'active_key' => null,
            'error_message' => 'Amazon在庫取込が制限時間内に完了しませんでした。',
        ]);
    }
}
