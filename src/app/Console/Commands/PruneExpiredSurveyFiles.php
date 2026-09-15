<?php

namespace App\Console\Commands;

use App\Models\SurveyFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('survey-files:prune')]
#[Description('アップロードから30日を経過した取込ファイルを削除します')]
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

        $this->info("{$deletedCount}件の期限切れファイルを削除しました。");

        return self::SUCCESS;
    }
}
