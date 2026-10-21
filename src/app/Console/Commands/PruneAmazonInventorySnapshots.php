<?php

namespace App\Console\Commands;

use App\Models\AmazonInventoryImport;
use App\Models\AmazonInventorySnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('amazon-inventory:prune-snapshots')]
#[Description('取込の対象日から30日を過ぎたAmazon在庫のスナップショットを削除します')]
class PruneAmazonInventorySnapshots extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deletedCount = 0;
        // 取込の記録（件数・日時など）は残し、SKU別のスナップショットだけを取込ごとに消す
        AmazonInventoryImport::query()
            ->where('snapshot_date', '<', AmazonInventoryImport::snapshotCutoffDate()->toDateString())
            ->whereHas('snapshots')
            ->orderBy('id')
            ->chunkById(100, function ($imports) use (&$deletedCount): void {
                foreach ($imports as $import) {
                    $deletedCount += AmazonInventorySnapshot::query()->where('amazon_inventory_import_id', $import->id)->delete();
                }
            });

        $this->info("{$deletedCount}件の保存期間を過ぎたスナップショットを削除しました。");

        return self::SUCCESS;
    }
}
