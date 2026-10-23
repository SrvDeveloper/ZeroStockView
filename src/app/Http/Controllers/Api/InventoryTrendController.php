<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInventoryTrendOverviewRequest;
use App\Http\Requests\ListInventoryTrendProductsRequest;
use App\Http\Requests\ShowInventoryTrendRequest;
use App\Http\Resources\SurveyResource;
use App\Models\Product;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Rules\InventoryHistoryProductCode;
use App\Rules\MaximumDateRange;
use App\Services\InventoryTrendCsvExporter;
use App\Services\InventoryTrendOverviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryTrendController extends Controller
{
    public function products(ListInventoryTrendProductsRequest $request): JsonResponse
    {
        // 在庫推移は調査の値だけで描く。Amazon在庫のスナップショットは調査に写すためだけに使う
        $baseQuery = Product::query()->whereIn('product_code', SurveyProduct::query()->select('product_code')->distinct());
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
        $surveyProductCodes = SurveyProduct::query()->whereIn('product_code', $products->pluck('product_code'))
            ->distinct()->pluck('product_code')->flip();

        $dateQuery = Survey::query()->whereHas('products');
        $firstDate = (clone $dateQuery)->min('executed_at');
        $lastDate = (clone $dateQuery)->max('executed_at');

        return response()->json([
            'data' => $products->map(fn (Product $product): array => [
                'productCode' => $product->product_code,
                'brand' => $product->brand,
                'category' => $product->category,
                'skuCount' => $product->skus_count,
                'hasSurveyHistory' => $surveyProductCodes->has($product->product_code),
            ])->values(),
            'brands' => $brands,
            'dateRange' => $firstDate !== null && $lastDate !== null ? [
                'from' => CarbonImmutable::parse($firstDate)->toDateString(),
                'to' => CarbonImmutable::parse($lastDate)->toDateString(),
            ] : null,
        ]);
    }

    /** 品番一覧。品番ごとの最新在庫・前週比・推移・SKUの状況・欠品と、上部の全体の数値を返す */
    public function overview(ListInventoryTrendOverviewRequest $request, InventoryTrendOverviewService $overview): JsonResponse
    {
        return response()->json($overview->overview($request->validated()));
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
            ->with(['amazonImport', 'products' => fn ($query) => $query->where('product_code', $productCode)->with('skus')])
            ->orderBy('executed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Survey $survey): string => $survey->executed_at->toDateString());
        $currentProduct = Product::query()->where('product_code', $productCode)->first();

        $dates = collect();
        for ($date = $from->startOfDay(); $date->lte($to); $date = $date->addDay()) {
            $dates->push($date->toDateString());
        }
        $skuMetadata = [];
        $quantities = [];
        $productSnapshot = null;
        $snapshotSurvey = null;
        $dailySources = [];
        $warehouseTotals = [];
        $breakdownWarehouses = match ($scope) {
            'mallTotal' => array_flip(['amazon', 'boss']),
            'grandTotal' => array_flip(['amazon', 'boss', 'free', 'stock']),
            default => null,
        };

        foreach ($dates as $date) {
            $survey = $dailySurveys->get($date);
            $selectedProductSnapshot = $survey?->products->first();
            if ($selectedProductSnapshot === null) {
                continue;
            }
            $productSnapshot = $selectedProductSnapshot;
            $snapshotSurvey = $survey;
            $basisAt = $survey->executed_at->toIso8601String();
            $dailySources[] = [
                'date' => $date,
                'source' => 'survey',
                'basisAt' => $basisAt,
                'amazonBasisAt' => $survey->amazon_source === Survey::AMAZON_SOURCE_SP_API
                    ? $survey->amazonImport?->basis_at?->toIso8601String()
                    : ($survey->hasSavedAmazonInventory() ? $basisAt : null),
                'amazonInventoryStatus' => $survey->amazonInventoryStatus(),
                // BOSS 在庫の取得方式（history: 在庫変動履歴から再現。docs/BOSS在庫の休日補完 BHR-07）
                'bossSource' => $survey->boss_source,
            ];
            $isSpApi = $survey->amazon_source === Survey::AMAZON_SOURCE_SP_API;
            $warehouseTotals[$date] = $this->sumWarehouses($selectedProductSnapshot->skus->map(
                fn (SurveySku $sku): array => $this->warehouseQuantities($sku, $isSpApi),
            )->all());
            foreach ($selectedProductSnapshot->skus as $sku) {
                if ($scope === 'amazon' && $survey->amazon_source === Survey::AMAZON_SOURCE_SP_API
                    && ! $sku->hasAmazonAsin() && $sku->amazon_own_stock === null && $sku->amazon_fba_stock === null) {
                    continue;
                }
                $skuMetadata[$sku->sku_code] = [
                    'skuCode' => $sku->sku_code,
                    'color' => $sku->tq_color_no,
                    'size' => $sku->tq_size,
                    'sortOrder' => $sku->sort_order,
                ];
                $quantities[$sku->sku_code][$date] = [
                    'quantity' => $this->quantity($sku, $scope, $isSpApi),
                    'source' => 'survey',
                    'basisAt' => $basisAt,
                ] + ($breakdownWarehouses === null ? [] : [
                    // 複数の倉庫を合わせる在庫区分では、ヒートマップのツールチップに倉庫別の内訳を出す
                    'warehouses' => array_intersect_key($this->warehouseQuantities($sku, $isSpApi), $breakdownWarehouses),
                ]);
            }
        }

        uasort($skuMetadata, fn (array $left, array $right): int => $left['sortOrder'] <=> $right['sortOrder']);
        // SKU・カラー・サイズの数は、期間内で最新の調査の品番（なければ今のマスタ）で数える
        $countedSkus = $productSnapshot?->skus ?? $currentProduct?->skus ?? collect();

        return response()->json([
            'data' => [
                'product' => $productSnapshot || $currentProduct ? [
                    'productCode' => $productCode,
                    'brand' => $productSnapshot?->brand ?? $currentProduct?->brand,
                    'category' => $productSnapshot?->category ?? $currentProduct?->category,
                    'groups' => $productSnapshot !== null ? $this->productGroups($productSnapshot, $snapshotSurvey) : [],
                    'skuCount' => $countedSkus->count(),
                    'colorCount' => $this->distinctCount($countedSkus->pluck('tq_color_no')),
                    'sizeCount' => $this->distinctCount($countedSkus->pluck('tq_size')),
                ] : null,
                'scope' => $scope,
                'from' => $data['from'],
                'to' => $data['to'],
                'dates' => $dates,
                'dailySources' => $dailySources,
                'warehouseTotals' => $dates->map(fn (string $date): array => ['date' => $date] + ($warehouseTotals[$date] ?? [
                    'amazon' => null,
                    'boss' => null,
                    'free' => null,
                    'stock' => null,
                ]))->values(),
                'series' => collect($skuMetadata)->map(fn (array $metadata, string $skuCode): array => [
                    'skuCode' => $metadata['skuCode'],
                    'color' => $metadata['color'],
                    'size' => $metadata['size'],
                    'points' => $dates->map(fn (string $date): array => ['date' => $date] + ($quantities[$skuCode][$date] ?? [
                        'quantity' => null,
                        'source' => null,
                        'basisAt' => null,
                    ]))->values(),
                ])->values(),
            ],
        ]);
    }

    public function export(Request $request, InventoryTrendCsvExporter $exporter): BinaryFileResponse
    {
        $data = $request->validate([
            'product_code' => ['sometimes', 'string', 'max:100', new InventoryHistoryProductCode],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', new MaximumDateRange($request->input('from'))],
        ]);
        $productCode = $data['product_code'] ?? null;
        $path = $exporter->export(
            $productCode,
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
        );
        $fileName = ($productCode ?? 'all_products').'_'.$data['from'].'_'.$data['to'].'_daily_inventory.csv';

        return response()->download($path, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ])->deleteFileAfterSend();
    }

    /**
     * 品番グループの所属。調査結果画面（SurveyResource）と同じく、どのグループにも入っていなければ「グループなし」。
     *
     * @return list<array{key: string, name: string}>
     */
    private function productGroups(SurveyProduct $snapshot, Survey $survey): array
    {
        if ($snapshot->group_memberships !== null) {
            // 再照合前の重複（`legacy:名前` と同名の `setting:ID`）は、同じ調査の品番グループのキーで1つにまとめる
            $settingKeysByName = SurveyProduct::query()->where('survey_id', $survey->id)->pluck('group_memberships')
                ->flatMap(fn (?array $memberships): array => $memberships ?? [])
                ->filter(fn (array $membership): bool => str_starts_with($membership['key'], 'setting:'))
                ->pluck('key', 'name')
                ->all();
            $memberships = SurveyProduct::mergeGroupMemberships($snapshot->group_memberships, $settingKeysByName);
        } else {
            $memberships = $survey->import_setting_name === null ? [] : [['key' => 'legacy:'.$survey->import_setting_name, 'name' => $survey->import_setting_name]];
        }
        if ($memberships === []) {
            return [['key' => SurveyResource::UNGROUPED_KEY, 'name' => 'グループなし']];
        }

        return array_map(fn (array $membership): array => ['key' => $membership['key'], 'name' => $membership['name']], $memberships);
    }

    /** 空欄を除いた値の種類の数 */
    private function distinctCount(Collection $values): int
    {
        return $values->map(fn (?string $value): string => trim((string) $value))->filter(fn (string $value): bool => $value !== '')->unique()->count();
    }

    private function quantity(SurveySku $sku, string $scope, bool $isSpApi): ?int
    {
        ['amazon' => $amazon, 'boss' => $boss, 'free' => $free, 'stock' => $stock] = $this->warehouseQuantities($sku, $isSpApi);

        return match ($scope) {
            'amazon' => $amazon,
            'boss' => $boss,
            'free' => $free,
            'stock' => $stock,
            'grandTotal' => $amazon === null || $boss === null || $free === null || $stock === null
                ? null : $amazon + $boss + $free + $stock,
            default => $amazon === null || $boss === null ? null : $amazon + $boss,
        };
    }

    /**
     * SKU の倉庫別数量。null は取込待ち・未取得を表す。
     * ASIN のない SP-API 調査の SKU は Amazon 取得の対象外なので、Amazon を 0 とする。
     *
     * @return array{amazon: int|null, boss: int|null, free: int|null, stock: int|null}
     */
    private function warehouseQuantities(SurveySku $sku, bool $isSpApi): array
    {
        $amazon = $sku->amazon_own_stock === null || $sku->amazon_fba_stock === null
            ? null
            : $sku->amazon_own_stock + $sku->amazon_fba_stock;
        if ($amazon === null && $isSpApi && ! $sku->hasAmazonAsin()) {
            $amazon = 0;
        }

        return [
            'amazon' => $amazon,
            'boss' => $sku->boss_own_stock === null || $sku->boss_rfc_stock === null
                ? null : $sku->boss_own_stock + $sku->boss_rfc_stock,
            'free' => $sku->free_stock,
            'stock' => $sku->ec_stock,
        ];
    }

    /**
     * 品番全体の倉庫別合計。1 SKU でも未確定（null）の倉庫は、部分合計を出さずに null とする。
     *
     * @param  list<array{amazon: int|null, boss: int|null, free: int|null, stock: int|null}>  $skuQuantities
     * @return array{amazon: int|null, boss: int|null, free: int|null, stock: int|null}
     */
    private function sumWarehouses(array $skuQuantities): array
    {
        $totals = [];
        foreach (['amazon', 'boss', 'free', 'stock'] as $warehouse) {
            $values = array_column($skuQuantities, $warehouse);
            $totals[$warehouse] = $values === [] || in_array(null, $values, true) ? null : array_sum($values);
        }

        return $totals;
    }
}
