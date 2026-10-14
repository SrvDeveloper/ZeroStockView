<?php

namespace App\Jobs;

use App\Models\AmazonInventoryImport;
use App\Services\AmazonInventoryImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAmazonInventoryImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 4200;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $importId)
    {
        $this->onConnection('amazon_inventory')->onQueue('amazon-inventory');
    }

    public function handle(AmazonInventoryImportService $service): void
    {
        $service->process($this->importId);
    }

    public function failed(?Throwable $exception): void
    {
        $failed = AmazonInventoryImport::query()
            ->whereKey($this->importId)
            ->whereIn('status', ['queued', 'running'])
            ->where('active_key', 1)
            ->update([
                'status' => 'failed',
                'active_key' => null,
                'completed_at' => now(),
                'error_message' => 'Amazon在庫取込ジョブが完了できませんでした。再実行してください。',
            ]);

        if ($failed > 0) {
            Log::error('Amazon在庫取込ジョブが完了できませんでした。', ['import_id' => $this->importId]);
        }
    }
}
