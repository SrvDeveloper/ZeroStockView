<?php

namespace App\Console\Commands;

use App\Services\SurveyAmazonInventorySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('survey:amazon-reconcile {date? : 対象日 YYYY-MM-DD。省略時は当日の未反映分のみ}')]
#[Description('Amazon在庫の取得結果を同日の在庫調査履歴に反映します')]
class ReconcileSurveyAmazonInventory extends Command
{
    public function handle(SurveyAmazonInventorySyncService $service): int
    {
        $argument = $this->argument('date');
        if ($argument !== null) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $argument, 'Asia/Tokyo');
                if ($date === null || $date->toDateString() !== $argument) {
                    throw new \InvalidArgumentException('Invalid date');
                }
            } catch (Throwable) {
                $this->error('対象日は YYYY-MM-DD 形式で指定してください。');

                return self::FAILURE;
            }
        } else {
            $date = CarbonImmutable::now('Asia/Tokyo')->startOfDay();
        }

        try {
            $count = $argument === null
                ? $service->syncPendingDate($date)
                : $service->syncDate($date);
        } catch (Throwable $exception) {
            Log::error('Amazon在庫の調査履歴への再反映に失敗しました。', [
                'snapshot_date' => $date->toDateString(),
                'exception' => $exception,
            ]);
            $this->error('調査履歴への反映に失敗しました。storage/logs/laravel.log を確認してください。');

            return self::FAILURE;
        }

        $this->info("{$date->toDateString()} の調査履歴 {$count} 件を反映しました。");

        return self::SUCCESS;
    }
}
