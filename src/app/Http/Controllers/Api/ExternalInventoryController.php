<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryTrendCsvExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalInventoryController extends Controller
{
    public function daily(Request $request, InventoryTrendCsvExporter $inventory): JsonResponse
    {
        $data = $request->validate([
            'product_code' => ['sometimes', 'string', 'max:100', 'exists:survey_products,product_code'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $rows = $inventory->rows(
            $data['product_code'] ?? null,
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
        );

        return response()->json([
            'data' => $rows,
            'meta' => [
                'from' => $data['from'],
                'to' => $data['to'],
                'productCode' => $data['product_code'] ?? null,
                'count' => count($rows),
            ],
        ]);
    }
}
