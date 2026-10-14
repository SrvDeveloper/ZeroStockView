<?php

namespace App\Console\Commands;

use App\Exceptions\AmazonInventoryImportException;
use App\Jobs\ProcessAmazonInventoryImport;
use App\Models\AmazonInventoryImport;
use App\Services\AmazonInventoryImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('amazon-inventory:queue')]
#[Description('Amazon在庫の定期取込をキューに登録します')]
class QueueScheduledAmazonInventoryImport extends Command
{
    public function handle(AmazonInventoryImportService $service): int
    {
        $today = now('Asia/Tokyo')->startOfDay();

        try {
            $import = DB::transaction(function () use ($service, $today): ?AmazonInventoryImport {
                if (AmazonInventoryImport::query()
                    ->where('trigger_source', 'scheduled')
                    ->where('created_at', '>=', $today)
                    ->where('created_at', '<', $today->copy()->addDay())
                    ->exists()) {
                    return null;
                }

                $import = $service->queue('scheduled');
                Bus::dispatch(new ProcessAmazonInventoryImport($import->id));

                return $import;
            });
        } catch (AmazonInventoryImportException $exception) {
            Log::warning('Amazon在庫の定期取込を投入できませんでした。', ['reason' => $exception->getMessage()]);
            $this->warn($exception->getMessage());

            return self::SUCCESS;
        }

        if ($import === null) {
            $this->info('本日のAmazon在庫定期取込は投入済みです。');

            return self::SUCCESS;
        }

        Log::info('Amazon在庫の定期取込を投入しました。', ['import_id' => $import->id]);
        $this->info("Amazon在庫の定期取込を投入しました（履歴 ID {$import->id}）。");

        return self::SUCCESS;
    }
}
