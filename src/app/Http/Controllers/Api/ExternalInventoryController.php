<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\InventoryHistoryProductCode;
use App\Rules\MaximumDateRange;
use App\Services\InventoryTrendCsvExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExternalInventoryController extends Controller
{
    public function daily(Request $request, InventoryTrendCsvExporter $inventory): StreamedResponse
    {
        $data = $request->validate([
            'product_code' => ['sometimes', 'string', 'max:100', new InventoryHistoryProductCode],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', new MaximumDateRange($request->input('from'))],
        ]);

        $rows = $inventory->rows(
            $data['product_code'] ?? null,
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
        );
        // 最初の日の分まで読み込んでから応答を始める（その間に失敗すれば、ふつうのエラー応答にできる）
        $rows->valid();
        $meta = [
            'from' => $data['from'],
            'to' => $data['to'],
            'productCode' => $data['product_code'] ?? null,
        ];

        // 全行を配列にせず、1行ずつJSONにして書き出す。内容は response()->json() と同じバイト列になる
        return response()->stream(function () use ($rows, $meta): void {
            echo '{"data":[';
            $count = 0;
            for (; $rows->valid(); $rows->next()) {
                echo ($count === 0 ? '' : ',').json_encode($rows->current(), JSON_THROW_ON_ERROR);
                $count++;
            }
            echo '],"meta":'.json_encode($meta + ['count' => $count], JSON_THROW_ON_ERROR).'}';
        }, 200, ['Content-Type' => 'application/json']);
    }
}
