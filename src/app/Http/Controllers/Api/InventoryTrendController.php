<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInventoryTrendProductsRequest;
use App\Http\Requests\ShowInventoryTrendRequest;
use App\Models\Product;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class InventoryTrendController extends Controller
{
    public function products(ListInventoryTrendProductsRequest $request): JsonResponse
    {
        $eligibleCodes = SurveyProduct::query()->select('product_code')->distinct();
        $baseQuery = Product::query()->whereIn('product_code', $eligibleCodes);
        $brands = (clone $baseQuery)->orderBy('brand')->distinct()->pluck('brand')->values();
        $data = $request->validated();

        $products = $baseQuery
            ->when($data['brand'] ?? null, fn ($query, string $brand) => $query->where('brand', $brand))
            ->when($data['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('product_code', 'like', '%'.$search.'%')
                        ->orWhere('brand', 'like', '%'.$search.'%')
                        ->orWhere('category', 'like', '%'.$search.'%');
                });
            })
            ->withCount('skus')
            ->orderBy('product_code')
            ->get();

        $dateQuery = Survey::query()->whereHas('products');
        $availableFrom = (clone $dateQuery)->min('executed_at');
        $availableTo = (clone $dateQuery)->max('executed_at');

        return response()->json([
            'data' => $products->map(fn (Product $product): array => [
                'productCode' => $product->product_code,
                'brand' => $product->brand,
                'category' => $product->category,
                'skuCount' => $product->skus_count,
            ])->values(),
            'brands' => $brands,
            'dateRange' => $availableFrom && $availableTo ? [
                'from' => CarbonImmutable::parse($availableFrom)->toDateString(),
                'to' => CarbonImmutable::parse($availableTo)->toDateString(),
            ] : null,
        ]);
    }

    public function show(ShowInventoryTrendRequest $request): JsonResponse
    {
        $data = $request->validated();
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();
        $productCode = $data['product_code'];
        $scope = $data['scope'];

        $dailySurveys = Survey::query()
            ->whereBetween('executed_at', [$from, $to])
            ->whereHas('products', fn ($query) => $query->where('product_code', $productCode))
            ->with(['products' => fn ($query) => $query->where('product_code', $productCode)->with('skus')])
            ->orderBy('executed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Survey $survey): string => $survey->executed_at->toDateString());

        $dates = collect();
        for ($date = $from->startOfDay(); $date->lte($to); $date = $date->addDay()) {
            $dates->push($date->toDateString());
        }
        $skuMetadata = [];
        $quantities = [];
        $productSnapshot = null;

        foreach ($dailySurveys as $date => $survey) {
            $productSnapshot = $survey->products->first();
            if ($productSnapshot === null) {
                continue;
            }

            foreach ($productSnapshot->skus as $sku) {
                $skuMetadata[$sku->sku_code] = [
                    'skuCode' => $sku->sku_code,
                    'size' => $sku->tq_size,
                    'sortOrder' => $sku->sort_order,
                ];
                $quantities[$sku->sku_code][$date] = $this->quantity($sku, $scope);
            }
        }

        uasort($skuMetadata, fn (array $left, array $right): int => $left['sortOrder'] <=> $right['sortOrder']);

        return response()->json([
            'data' => [
                'product' => $productSnapshot ? [
                    'productCode' => $productSnapshot->product_code,
                    'brand' => $productSnapshot->brand,
                    'category' => $productSnapshot->category,
                ] : null,
                'scope' => $scope,
                'from' => $data['from'],
                'to' => $data['to'],
                'dates' => $dates,
                'series' => collect($skuMetadata)->map(fn (array $metadata, string $skuCode): array => [
                    'skuCode' => $metadata['skuCode'],
                    'size' => $metadata['size'],
                    'points' => $dates->map(fn (string $date): array => [
                        'date' => $date,
                        'quantity' => $quantities[$skuCode][$date] ?? null,
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    private function quantity(SurveySku $sku, string $scope): int
    {
        $amazon = $sku->amazon_own_stock + $sku->amazon_fba_stock;
        $boss = $sku->boss_own_stock + $sku->boss_rfc_stock;

        return match ($scope) {
            'amazon' => $amazon,
            'boss' => $boss,
            'free' => $sku->free_stock,
            'stock' => $sku->ec_stock,
            'grandTotal' => $amazon + $boss + $sku->free_stock + $sku->ec_stock,
            default => $amazon + $boss,
        };
    }
}
