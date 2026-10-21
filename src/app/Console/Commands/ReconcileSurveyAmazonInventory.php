<?php

namespace App\Console\Commands;

use App\Services\InventoryImportService;
use App\Services\SurveyAmazonInventorySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('survey:amazon-reconcile {date? : 対象日 YYYY-MM-DD。省略時は当日の未反映分のみ} {--create-survey : 成功したAmazon在庫取込があって調査がない日は、Amazon在庫だけの調査を作成する}')]
#[Description('Amazon在庫の取得結果を同日の在庫調査履歴に反映します')]
class ReconcileSurveyAmazonInventory extends Command
{
    public function handle(SurveyAmazonInventorySyncService $service, InventoryImportService $importer): int
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

        if ($this->option('create-survey') && ! $this->createSurvey($date, $service, $importer)) {
            return self::FAILURE;
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

    /** 自動作成の失敗時の復旧と、機能追加前に取込済みだった日の補完に使う。5分ごとの定期実行には付けない（削除した調査を作り直さないため） */
    private function createSurvey(CarbonImmutable $date, SurveyAmazonInventorySyncService $service, InventoryImportService $importer): bool
    {
        $import = $service->latestSuccessfulImport($date->toDateString());
        if ($import === null) {
            $this->info("{$date->toDateString()} は成功したAmazon在庫取込がないため、調査を作成しません。");

            return true;
        }
        if ($import->snapshotsExpired() && ! $import->snapshots()->exists()) {
            $this->info("{$date->toDateString()} はAmazon在庫の記録が保存期間を過ぎて削除されているため、調査を作成しません。");

            return true;
        }

        try {
            $survey = $importer->createFromAmazonImport($import);
        } catch (Throwable $exception) {
            Log::error('Amazon在庫取込からの調査の作成に失敗しました。', [
                'import_id' => $import->id,
                'snapshot_date' => $date->toDateString(),
                'exception' => $exception,
            ]);
            $this->error('調査の作成に失敗しました。storage/logs/laravel.log を確認してください。');

            return false;
        }

        $this->info($survey === null
            ? "{$date->toDateString()} の調査はすでにあるため、作成しませんでした。"
            : "{$date->toDateString()} のAmazon在庫だけの調査を作成しました。");

        return true;
    }
}
