<?php

namespace App\Services;

use App\Models\Survey;

class SurveyAmazonInventoryClassifier
{
    private const AMAZON_FILES = ['在庫商品レポート', 'FBA在庫管理レポート'];

    private const OTHER_FILES = ['倉庫毎の在庫数レポート', 'KEEP一覧表', '在庫一覧照会表'];

    /**
     * @param  array<int, string>  $files
     * @return array{string, array<int, string>}
     */
    public function classify(bool $includesAmazon, ?int $importId, array $files, int $skuCount, int $nonzeroCount, ?string $source = null): array
    {
        $hasAmazonFile = array_intersect($files, self::AMAZON_FILES) !== [];
        $hasOtherFile = array_intersect($files, self::OTHER_FILES) !== [];
        $hasUnknownFile = array_diff($files, self::AMAZON_FILES, self::OTHER_FILES) !== [];

        if ($skuCount === 0 || $hasUnknownFile) {
            return ['ambiguous', ['調査種別を確定できないため自動移行しない']];
        }
        if ($source === Survey::AMAZON_SOURCE_SP_API) {
            return ! $includesAmazon && ! $hasAmazonFile
                ? ['three_file', []]
                : ['ambiguous', ['明示された取得方式とファイル・旧フラグが矛盾']];
        }
        if ($source === Survey::AMAZON_SOURCE_LEGACY_FILE) {
            return $includesAmazon && $importId === null && ($hasAmazonFile || ! $hasOtherFile)
                ? ['legacy_file', []]
                : ['ambiguous', ['明示された取得方式とファイル・旧フラグが矛盾']];
        }
        if ($source !== null || ($includesAmazon && $importId !== null)) {
            return ['ambiguous', ['調査種別を確定できないため自動移行しない']];
        }
        if (! $includesAmazon) {
            if ($hasAmazonFile || $nonzeroCount > 0) {
                return ['ambiguous', ['3ファイルのフラグとファイル・保存数量が矛盾']];
            }

            return ['three_file', []];
        }
        if ($hasAmazonFile || (! $hasOtherFile && $nonzeroCount > 0)) {
            return ['legacy_file', []];
        }

        return ['ambiguous', ['旧5ファイルのフラグだけでは保存期限切れのファイル構成を判定できない']];
    }
}
