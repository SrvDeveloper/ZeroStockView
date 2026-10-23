<?php

namespace App\Console\Commands;

use App\Models\BossInventoryHistory;
use App\Models\SurveyFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('survey-files:prune')]
#[Description('アップロードから30日を経過した取込ファイルと在庫変動履歴のファイルを削除します')]
class PruneExpiredSurveyFiles extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deletedCount = 0;
        SurveyFile::query()
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($files) use (&$deletedCount): void {
                foreach ($files as $file) {
                    Storage::disk($file->disk)->delete($file->stored_path);
                    $file->delete();
                    $deletedCount++;
                }
            });

        // 在庫変動履歴は、ファイルだけを消して取込記録の行は残す（どの日をどの履歴で補完したかを残すため）
        BossInventoryHistory::query()
            ->where('expires_at', '<=', now())
            ->whereNotNull('stored_path')
            ->orderBy('id')
            ->chunkById(100, function ($histories) use (&$deletedCount): void {
                foreach ($histories as $history) {
                    Storage::disk($history->disk)->delete($history->stored_path);
                    $history->forceFill(['disk' => null, 'stored_path' => null])->save();
                    $deletedCount++;
                }
            });

        $this->info("{$deletedCount}件の期限切れファイルを削除しました。");

        return self::SUCCESS;
    }
}
