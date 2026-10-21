<?php

namespace App\Services;

use App\Models\Survey;
use Illuminate\Support\Facades\Storage;

/**
 * 調査履歴の件数の上限を守る。上限を超えたら、調査日の古い調査から、取込ファイルとメモごと削除する。
 */
class SurveyHistoryPruner
{
    /** 残す調査履歴の件数 */
    public const MAX_SURVEYS = 500;

    /**
     * 調査日の新しい順に MAX_SURVEYS 件を残し、それより古い調査を削除する。
     * 何度実行しても同じ結果になるので、同時に実行されても削除しすぎない。
     *
     * @return int 削除した調査の件数
     */
    public function prune(): int
    {
        $keptIds = Survey::query()
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->limit(self::MAX_SURVEYS)
            ->pluck('id');
        if ($keptIds->count() < self::MAX_SURVEYS) {
            return 0;
        }

        $deletedCount = 0;
        Survey::query()
            ->whereNotIn('id', $keptIds)
            ->with('files')
            ->orderBy('id')
            ->chunkById(100, function ($surveys) use (&$deletedCount): void {
                foreach ($surveys as $survey) {
                    foreach ($survey->files as $file) {
                        Storage::disk($file->disk)->delete($file->stored_path);
                    }
                    // 調査の品番・SKU（メモを含む）と取込ファイルの記録は、外部キーで一緒に消える
                    $survey->delete();
                    $deletedCount++;
                }
            });

        return $deletedCount;
    }
}
