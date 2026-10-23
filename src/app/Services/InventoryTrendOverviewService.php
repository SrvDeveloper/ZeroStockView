<?php

namespace App\Services;

use App\Http\Resources\SurveyResource;
use App\Models\Survey;
use App\Models\SurveyProduct;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 在庫推移の品番一覧。調査の保存値（survey_skus）を調査 × 品番で SQL で集計し、品番ごとの最新在庫・前週比・推移・欠品をまとめる。
 * 在庫区分ごとの数量の決め方は InventoryTrendController::quantity()・warehouseQuantities() と同じ規則を SQL の式で書く
 * （同じ結果になることを InventoryTrendOverviewApiTest で固定している）。
 */
class InventoryTrendOverviewService
{
    public const PER_PAGE = 25;

    /** 長く続いている欠品とみなす連続日数 */
    public const LONG_STOCKOUT_DAYS = 30;

    /** 連続欠品日数をさかのぼって数える上限。表示期間に関係なく、最新の調査日から数える */
    public const STREAK_LOOKBACK_DAYS = 90;

    public const FOCUSES = ['stockout', 'increased', 'decreasing', 'long'];

    public const SORTS = ['stockout', 'delta', 'latest', 'code'];

    /** 前週比は7日前の調査と比べ、なければそれより前で最も近い調査（14日前まで）と比べる */
    private const WEEK_DAYS = 7;

    private const WEEK_FALLBACK_DAYS = 14;

    /** 「在庫が10%以上減った」の境目 */
    private const DECREASE_RATE = -0.1;

    /**
     * @param  array{from: string, to: string, scope: string, group?: string|null, brand?: string|null, search?: string|null, focus?: string|null, sort?: string|null, page?: int|string|null}  $params
     * @return array<string, mixed>
     */
    public function overview(array $params): array
    {
        $from = CarbonImmutable::parse($params['from'])->startOfDay();
        $to = CarbonImmutable::parse($params['to'])->endOfDay();
        $scope = $params['scope'];
        $page = max(1, (int) ($params['page'] ?? 1));

        $latestSurvey = Survey::query()
            ->whereBetween('executed_at', [$from, $to])
            ->whereHas('products')
            ->orderByDesc('executed_at')->orderByDesc('created_at')->orderByDesc('id')
            ->first();
        if ($latestSurvey === null) {
            return $this->response([], $this->summary(collect()), null, $page, 0, [], [], $from, $to);
        }

        $latestDate = CarbonImmutable::parse($latestSurvey->executed_at->toDateString());
        $lookbackStart = $latestDate->subDays(self::STREAK_LOOKBACK_DAYS);
        $surveys = Survey::query()
            ->whereBetween('executed_at', [$lookbackStart->lessThan($from) ? $lookbackStart : $from, $to])
            ->whereHas('products')
            ->orderBy('executed_at')->orderBy('created_at')->orderBy('id')
            ->get(['id', 'executed_at', 'import_setting_name']);
        $surveyDates = $surveys->mapWithKeys(fn (Survey $survey): array => [$survey->id => $survey->executed_at->toDateString()])->all();
        $allowedPairs = $this->dailyProductSurveys($surveys);

        $daily = $this->dailyTotals($scope, array_keys($surveyDates), $surveyDates, $allowedPairs);
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $periodDates = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $periodDates[] = $date->toDateString();
        }
        $products = collect($daily)
            ->map(function (array $days, string $productCode) use ($fromDate, $toDate): ?array {
                $inPeriod = array_filter(array_keys($days), fn (string $date): bool => $date >= $fromDate && $date <= $toDate);

                return $inPeriod === [] ? null : ['productCode' => $productCode, 'days' => $days, 'latestDate' => max($inPeriod)];
            })
            ->filter()
            ->sortKeys(SORT_STRING)
            ->values();

        $snapshots = $this->latestSnapshots($products, $surveys);
        $skuStates = $this->latestSkuStates($scope, $snapshots);
        $stockoutSkus = [];
        foreach ($snapshots as $productCode => $snapshot) {
            foreach ($skuStates->get($snapshot['id'], []) as $state) {
                if ($state['state'] === 'stockout') {
                    $stockoutSkus[$state['skuCode']] = $productCode;
                }
            }
        }
        $streaks = $this->stockoutStreaks($scope, $surveys, $surveyDates, $latestDate, $lookbackStart, $stockoutSkus);
        $groupKeysByName = $snapshots
            ->flatMap(fn (array $snapshot): array => $snapshot['memberships'] ?? [])
            ->filter(fn (array $membership): bool => str_starts_with($membership['key'], 'setting:'))
            ->pluck('key', 'name')
            ->all();

        $rows = $products->map(function (array $product) use ($snapshots, $skuStates, $streaks, $groupKeysByName, $periodDates): array {
            $productCode = $product['productCode'];
            $snapshot = $snapshots->get($productCode);
            $latest = $product['days'][$product['latestDate']];
            $weekAgoDate = $this->weekAgoDate($product['days'], $product['latestDate']);
            $weekAgo = $weekAgoDate === null ? null : $product['days'][$weekAgoDate];
            $states = $skuStates->get($snapshot['id'], []);
            $productStreaks = collect($states)
                ->filter(fn (array $state): bool => $state['state'] === 'stockout')
                ->map(fn (array $state): int => $this->streakDays($streaks[$productCode][$state['skuCode']] ?? null, $product['latestDate']))
                ->values();

            return [
                'productCode' => $productCode,
                'brand' => $snapshot['brand'],
                'category' => $snapshot['category'],
                'skuCount' => count($states),
                'groups' => $this->groups($snapshot, $groupKeysByName),
                'latest' => ['date' => $product['latestDate'], 'quantity' => $latest['total']],
                'weekAgo' => $weekAgo === null ? null : ['date' => $weekAgoDate, 'quantity' => $weekAgo['total']],
                'days' => $product['days'],
                'skuStates' => array_column($states, 'state'),
                'stockoutSkuCount' => $latest['stockout'],
                'stockoutSkuCountWeekAgo' => $weekAgo['stockout'] ?? null,
                'stockoutSkuDays' => array_sum(array_map(fn (string $date): int => $product['days'][$date]['stockout'] ?? 0, $periodDates)),
                'longestStockoutStreak' => $productStreaks->max(),
                'longStockoutSkuCount' => $productStreaks->filter(fn (int $days): bool => $days >= self::LONG_STOCKOUT_DAYS)->count(),
            ];
        });

        $groupOptions = $this->groupOptions($rows);
        $brands = $rows->pluck('brand')->unique()->sort(SORT_STRING)->values()->all();

        $filtered = $rows
            ->when($params['group'] ?? null, fn (Collection $rows, string $group): Collection => $rows
                ->filter(fn (array $row): bool => in_array($group, array_column($row['groups'], 'key'), true)))
            ->when($params['brand'] ?? null, fn (Collection $rows, string $brand): Collection => $rows
                ->filter(fn (array $row): bool => $row['brand'] === $brand))
            ->when($params['search'] ?? null, function (Collection $rows, string $search) use ($surveyDates, $fromDate, $toDate): Collection {
                $periodSurveyIds = array_keys(array_filter($surveyDates, fn (string $date): bool => $date >= $fromDate && $date <= $toDate));
                $skuMatches = array_flip($this->productCodesMatchingSku($search, $periodSurveyIds));

                return $rows->filter(fn (array $row): bool => mb_stripos($row['productCode'], $search) !== false || isset($skuMatches[$row['productCode']]));
            })
            ->values();

        $summary = $this->summary($filtered);
        $focused = ($focus = $params['focus'] ?? null) === null ? $filtered : $filtered->filter(fn (array $row): bool => $this->matchesFocus($row, $focus));
        $sorted = $this->sort($focused->values(), $params['sort'] ?? 'stockout');
        // 推移はページに出す品番の分だけ作る
        $pageRows = $sorted->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)
            ->map(fn (array $row): array => array_diff_key($row, ['longStockoutSkuCount' => true, 'days' => true]) + [
                'points' => array_map(fn (string $date): array => ['date' => $date, 'quantity' => $row['days'][$date]['total'] ?? null], $periodDates),
            ])
            ->values()
            ->all();

        return $this->response($pageRows, $summary, $this->latestSurveyPayload($latestSurvey, $latestDate), $page, $sorted->count(), $brands, $groupOptions, $from, $to);
    }

    /**
     * 在庫区分の数量を表す SQL の式。`NULL` は取込待ち・未取得・データなし（未確定）。
     * ASIN のない SP-API 調査の SKU は Amazon 取得の対象外なので、Amazon を 0 とする（InventoryTrendController::warehouseQuantities()）。
     */
    private function expression(string $scope): string
    {
        $spApi = Survey::AMAZON_SOURCE_SP_API;
        $amazon = '(CASE WHEN ss.amazon_own_stock IS NOT NULL AND ss.amazon_fba_stock IS NOT NULL THEN ss.amazon_own_stock + ss.amazon_fba_stock'
            ." WHEN s.amazon_source = '{$spApi}' AND (ss.child_asin IS NULL OR TRIM(ss.child_asin) = '') THEN 0 END)";
        $boss = '(CASE WHEN ss.boss_own_stock IS NOT NULL AND ss.boss_rfc_stock IS NOT NULL THEN ss.boss_own_stock + ss.boss_rfc_stock END)';

        return match ($scope) {
            'amazon' => $amazon,
            'boss' => $boss,
            'free' => 'ss.free_stock',
            'stock' => 'ss.ec_stock',
            'grandTotal' => "({$amazon} + {$boss} + ss.free_stock + ss.ec_stock)",
            default => "({$amazon} + {$boss})",
        };
    }

    /** SKU を結合した集計の土台。Amazon の区分では、Amazon 取得の対象外の SKU を除く（在庫推移の SKU 別と同じ） */
    private function skuQuery(string $scope): Builder
    {
        $spApi = Survey::AMAZON_SOURCE_SP_API;

        return DB::table('survey_skus as ss')
            ->join('survey_products as sp', 'sp.id', '=', 'ss.survey_product_id')
            ->join('surveys as s', 's.id', '=', 'sp.survey_id')
            ->when($scope === 'amazon', fn (Builder $query): Builder => $query->whereRaw(
                "NOT (s.amazon_source = '{$spApi}' AND (ss.child_asin IS NULL OR TRIM(ss.child_asin) = '') AND ss.amazon_own_stock IS NULL AND ss.amazon_fba_stock IS NULL)",
            ));
    }

    /**
     * 同じ日に調査が複数ある日（日次化より前の履歴）は、品番ごとにその日の最後の調査を使う（在庫推移・日次CSVと同じ）。
     *
     * @param  Collection<int, Survey>  $surveys  日時の古い順
     * @return array<int, array<string, true>|null> 調査ID => 使う品番（null はその調査の全品番）
     */
    private function dailyProductSurveys(Collection $surveys): array
    {
        $allowed = [];
        foreach ($surveys->groupBy(fn (Survey $survey): string => $survey->executed_at->toDateString()) as $daySurveys) {
            if ($daySurveys->count() === 1) {
                $allowed[$daySurveys->first()->id] = null;

                continue;
            }
            $lastSurveyByProduct = [];
            $ids = $daySurveys->pluck('id')->all();
            $order = array_flip($ids);
            foreach (DB::table('survey_products')->whereIn('survey_id', $ids)->get(['survey_id', 'product_code']) as $row) {
                $current = $lastSurveyByProduct[$row->product_code] ?? null;
                if ($current === null || $order[$row->survey_id] > $order[$current]) {
                    $lastSurveyByProduct[$row->product_code] = $row->survey_id;
                }
            }
            foreach ($ids as $id) {
                $allowed[$id] = [];
            }
            foreach ($lastSurveyByProduct as $productCode => $surveyId) {
                $allowed[$surveyId][$productCode] = true;
            }
        }

        return $allowed;
    }

    /**
     * 品番 × 日の合計と欠品 SKU 数。1 SKU でも未確定なら合計は null（InventoryTrendController::sumWarehouses() と同じ）。
     *
     * @param  list<int>  $surveyIds
     * @param  array<int, string>  $surveyDates
     * @param  array<int, array<string, true>|null>  $allowedPairs
     * @return array<string, array<string, array{total: int|null, stockout: int, surveyId: int}>>
     */
    private function dailyTotals(string $scope, array $surveyIds, array $surveyDates, array $allowedPairs): array
    {
        $expression = $this->expression($scope);
        $daily = [];
        foreach (array_chunk($surveyIds, 500) as $chunk) {
            $rows = $this->skuQuery($scope)
                ->whereIn('sp.survey_id', $chunk)
                ->groupBy('sp.survey_id', 'sp.product_code')
                ->selectRaw("sp.survey_id, sp.product_code, COUNT(*) AS sku_count, COUNT({$expression}) AS known_count, SUM({$expression}) AS total, SUM(CASE WHEN {$expression} = 0 THEN 1 ELSE 0 END) AS stockout_count")
                ->get();
            foreach ($rows as $row) {
                $allowed = $allowedPairs[$row->survey_id] ?? null;
                if ($allowed !== null && ! isset($allowed[$row->product_code])) {
                    continue;
                }
                $daily[$row->product_code][$surveyDates[$row->survey_id]] = [
                    'total' => (int) $row->known_count === (int) $row->sku_count ? (int) $row->total : null,
                    'stockout' => (int) $row->stockout_count,
                    'surveyId' => (int) $row->survey_id,
                ];
            }
        }

        return $daily;
    }

    /**
     * 品番ごとの、期間内で最新の調査の品番情報（ブランド・カテゴリ・グループ所属）。
     *
     * @param  Collection<int, array{productCode: string, days: array<string, array{total: int|null, stockout: int, surveyId: int}>, latestDate: string}>  $products
     * @param  Collection<int, Survey>  $surveys
     * @return Collection<string, array{id: int, brand: string, category: string, memberships: list<array{key: string, name: string, sortOrder: int}>|null, sortOrder: int, importSettingName: string|null}>
     */
    private function latestSnapshots(Collection $products, Collection $surveys): Collection
    {
        $latestSurveyIds = $products->mapWithKeys(fn (array $product): array => [$product['productCode'] => $product['days'][$product['latestDate']]['surveyId']]);
        $settingNames = $surveys->pluck('import_setting_name', 'id');
        $snapshots = collect();
        foreach ($latestSurveyIds->unique()->chunk(500) as $chunk) {
            $rows = DB::table('survey_products')
                ->whereIn('survey_id', $chunk->values()->all())
                ->get(['id', 'survey_id', 'product_code', 'brand', 'category', 'group_memberships', 'sort_order']);
            foreach ($rows as $row) {
                if ($latestSurveyIds->get($row->product_code) !== (int) $row->survey_id) {
                    continue;
                }
                $snapshots->put($row->product_code, [
                    'id' => (int) $row->id,
                    'brand' => $row->brand,
                    'category' => $row->category,
                    'memberships' => $row->group_memberships === null ? null : json_decode($row->group_memberships, true),
                    'sortOrder' => (int) $row->sort_order,
                    'importSettingName' => $settingNames->get($row->survey_id),
                ]);
            }
        }

        return $snapshots;
    }

    /**
     * 最新の調査の SKU ごとの状態（SKU の並び順）。
     *
     * @param  Collection<string, array{id: int}>  $snapshots
     * @return Collection<int, list<array{skuCode: string, state: string}>> 調査品番ID => SKU の状態
     */
    private function latestSkuStates(string $scope, Collection $snapshots): Collection
    {
        $expression = $this->expression($scope);
        $states = collect();
        foreach ($snapshots->pluck('id')->chunk(500) as $chunk) {
            $rows = $this->skuQuery($scope)
                ->whereIn('ss.survey_product_id', $chunk->values()->all())
                ->orderBy('ss.survey_product_id')->orderBy('ss.sort_order')->orderBy('ss.id')
                ->selectRaw("ss.survey_product_id, ss.sku_code, {$expression} AS quantity")
                ->get();
            foreach ($rows->groupBy('survey_product_id') as $surveyProductId => $skus) {
                $states->put((int) $surveyProductId, $skus->map(fn (object $sku): array => [
                    'skuCode' => $sku->sku_code,
                    'state' => $sku->quantity === null ? 'pending' : ((int) $sku->quantity === 0 ? 'stockout' : 'inStock'),
                ])->all());
            }
        }

        return $states;
    }

    /**
     * 最新の調査で欠品している SKU ごとの、さかのぼれる範囲で最後に在庫（1以上）があった日と、その後で最初に欠品（確定した 0）と確定した日。
     * 欠品はこの日から始まったとみなす（その前の調査のない日・取込待ち（NULL）の日は欠品に数えない）。
     * 欠品と確定した日のあとの、調査のない日と取込待ちの日は、欠品の連続を切らない。
     * 同じ日に調査が複数ある古い期間では、その日のどれかの調査で在庫があれば在庫ありとみなす。
     *
     * @param  Collection<int, Survey>  $surveys
     * @param  array<int, string>  $surveyDates
     * @param  array<string, string>  $stockoutSkus  最新の調査で欠品している SKU コード => 品番
     * @return array<string, array<string, array{lastInStock: string|null, stockoutSince: string|null}>>
     */
    private function stockoutStreaks(string $scope, Collection $surveys, array $surveyDates, CarbonImmutable $latestDate, CarbonImmutable $lookbackStart, array $stockoutSkus): array
    {
        $expression = $this->expression($scope);
        $ids = $surveys->filter(fn (Survey $survey): bool => $surveyDates[$survey->id] >= $lookbackStart->toDateString()
            && $surveyDates[$survey->id] <= $latestDate->toDateString())->pluck('id')->all();
        /** @var array<string, array<string, array<string, bool>>> $days 品番 => SKU => 日 => 在庫ありか（欠品と確定した日は false） */
        $days = [];
        // SKU コードで絞ると索引の引き方が悪くなるので、品番で絞って SKU は PHP で選ぶ
        foreach (array_chunk(array_values(array_unique($stockoutSkus)), 500) as $productCodes) {
            foreach (array_chunk($ids, 500) as $chunk) {
                // 調査 × SKU は1行なので、集計せずに取り出し、日ごとの判定は PHP で行う
                $rows = $this->skuQuery($scope)
                    ->whereIn('sp.survey_id', $chunk)
                    ->whereIn('sp.product_code', $productCodes)
                    ->whereRaw("{$expression} IS NOT NULL")
                    ->selectRaw("sp.product_code, ss.sku_code, s.executed_at, CASE WHEN {$expression} > 0 THEN 1 ELSE 0 END AS in_stock")
                    ->get();
                foreach ($rows as $row) {
                    if (($stockoutSkus[$row->sku_code] ?? null) !== $row->product_code) {
                        continue;
                    }
                    $date = substr((string) $row->executed_at, 0, 10);
                    $days[$row->product_code][$row->sku_code][$date] = (bool) $row->in_stock || ($days[$row->product_code][$row->sku_code][$date] ?? false);
                }
            }
        }

        $streaks = [];
        foreach ($days as $productCode => $skus) {
            foreach ($skus as $skuCode => $states) {
                ksort($states);
                $inStockDates = array_keys(array_filter($states));
                $lastInStock = $inStockDates === [] ? null : max($inStockDates);
                $stockoutDates = array_keys(array_filter($states, fn (bool $inStock, string $date): bool => ! $inStock && ($lastInStock === null || $date > $lastInStock), ARRAY_FILTER_USE_BOTH));
                $streaks[$productCode][$skuCode] = ['lastInStock' => $lastInStock, 'stockoutSince' => $stockoutDates === [] ? null : min($stockoutDates)];
            }
        }

        return $streaks;
    }

    /**
     * 最新の調査日まで続いている欠品の日数。最後に在庫があった日の後で、最初に欠品と確定した日から数える
     * （さかのぼれる範囲に在庫のあった日がなければ、範囲内で最初に欠品と確定した日から）。
     *
     * @param  array{lastInStock: string|null, stockoutSince: string|null}|null  $streak
     */
    private function streakDays(?array $streak, string $latestDate): int
    {
        return (int) CarbonImmutable::parse($streak['stockoutSince'] ?? $latestDate)->diffInDays(CarbonImmutable::parse($latestDate)) + 1;
    }

    /**
     * 前週比で比べる日。7日前に調査があればその日、なければそれより前で最も近い調査（14日前まで）。
     *
     * @param  array<string, mixed>  $days
     */
    private function weekAgoDate(array $days, string $latestDate): ?string
    {
        $latest = CarbonImmutable::parse($latestDate);
        $target = $latest->subDays(self::WEEK_DAYS)->toDateString();
        $earliest = $latest->subDays(self::WEEK_FALLBACK_DAYS)->toDateString();
        $candidates = array_filter(array_keys($days), fn (string $date): bool => $date >= $earliest && $date <= $target);

        return $candidates === [] ? null : max($candidates);
    }

    /**
     * 品番グループの所属。調査結果画面（SurveyResource）と同じく、どのグループにも入っていなければ「グループなし」。
     *
     * @param  array{memberships: list<array{key: string, name: string, sortOrder: int}>|null, importSettingName: string|null}  $snapshot
     * @param  array<string, string>  $groupKeysByName
     * @return list<array{key: string, name: string}>
     */
    private function groups(array $snapshot, array $groupKeysByName): array
    {
        $memberships = match (true) {
            $snapshot['memberships'] !== null => SurveyProduct::mergeGroupMemberships($snapshot['memberships'], $groupKeysByName),
            $snapshot['importSettingName'] !== null => [['key' => 'legacy:'.$snapshot['importSettingName'], 'name' => $snapshot['importSettingName']]],
            default => [],
        };
        if ($memberships === []) {
            return [['key' => SurveyResource::UNGROUPED_KEY, 'name' => 'グループなし']];
        }

        return array_map(fn (array $membership): array => ['key' => $membership['key'], 'name' => $membership['name']], $memberships);
    }

    /**
     * グループの選択肢。品番グループ（作った順）、旧方式のグループ名、グループなしの順。
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{key: string, name: string}>
     */
    private function groupOptions(Collection $rows): array
    {
        return $rows->flatMap(fn (array $row): array => $row['groups'])
            ->unique('key')
            ->sortBy(fn (array $group): array => match (true) {
                str_starts_with($group['key'], 'setting:') => [0, (int) substr($group['key'], 8), ''],
                $group['key'] === SurveyResource::UNGROUPED_KEY => [2, 0, ''],
                default => [1, 0, $group['name']],
            })
            ->values()
            ->all();
    }

    /**
     * 期間内の調査で、SKU コードか ASIN に検索語を含む SKU を持つ品番。
     *
     * @param  list<int>  $surveyIds
     * @return list<string>
     */
    private function productCodesMatchingSku(string $search, array $surveyIds): array
    {
        $codes = [];
        foreach (array_chunk($surveyIds, 500) as $chunk) {
            $codes = array_merge($codes, DB::table('survey_skus as ss')
                ->join('survey_products as sp', 'sp.id', '=', 'ss.survey_product_id')
                ->whereIn('sp.survey_id', $chunk)
                ->where(fn (Builder $query): Builder => $query->where('ss.sku_code', 'like', '%'.$search.'%')->orWhere('ss.child_asin', 'like', '%'.$search.'%'))
                ->distinct()
                ->pluck('sp.product_code')
                ->all());
        }

        return array_values(array_unique($codes));
    }

    /** @param array<string, mixed> $row */
    private function delta(array $row): ?float
    {
        $latest = $row['latest']['quantity'];
        $weekAgo = $row['weekAgo']['quantity'] ?? null;

        return $latest === null || $weekAgo === null || $weekAgo === 0 ? null : ($latest - $weekAgo) / $weekAgo;
    }

    /** @param array<string, mixed> $row */
    private function matchesFocus(array $row, string $focus): bool
    {
        return match ($focus) {
            'stockout' => $row['stockoutSkuCount'] > 0,
            'increased' => $row['stockoutSkuCountWeekAgo'] !== null && $row['stockoutSkuCount'] > $row['stockoutSkuCountWeekAgo'],
            'decreasing' => ($delta = $this->delta($row)) !== null && $delta <= self::DECREASE_RATE,
            'long' => $row['longStockoutSkuCount'] > 0,
            default => true,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sort(Collection $rows, string $sort): Collection
    {
        // 値のない品番（比べられない・未確定）は最後にする
        $nullsLast = fn (int|float|null $left, int|float|null $right): int => match (true) {
            $left === null && $right === null => 0,
            $left === null => 1,
            $right === null => -1,
            default => $left <=> $right,
        };

        return $rows->sort(fn (array $left, array $right): int => match ($sort) {
            'delta' => $nullsLast($this->delta($left), $this->delta($right)),
            'latest' => $nullsLast($left['latest']['quantity'], $right['latest']['quantity']),
            'code' => 0,
            default => ($right['stockoutSkuCount'] <=> $left['stockoutSkuCount']) ?: $nullsLast($this->delta($left), $this->delta($right)),
        } ?: strcmp($left['productCode'], $right['productCode']))->values();
    }

    /**
     * 上部の数値。注目の絞り込み（focus）をかける前の品番で数える。
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $weekAgoRows = $rows->filter(fn (array $row): bool => $row['weekAgo'] !== null);
        $sumOrNull = fn (Collection $values): ?int => $values->isEmpty() || $values->contains(null) ? null : (int) $values->sum();

        return [
            'productCount' => $rows->count(),
            'skuCount' => $rows->sum('skuCount'),
            'stockoutSkuCount' => $rows->sum('stockoutSkuCount'),
            'stockoutSkuCountWeekAgo' => $weekAgoRows->isEmpty() ? null : $weekAgoRows->sum('stockoutSkuCountWeekAgo'),
            'longStockoutSkuCount' => $rows->sum('longStockoutSkuCount'),
            'longStockoutProductCount' => $rows->filter(fn (array $row): bool => $row['longStockoutSkuCount'] > 0)->count(),
            'totalQuantity' => $sumOrNull($rows->map(fn (array $row): ?int => $row['latest']['quantity'])),
            'totalQuantityWeekAgo' => $sumOrNull($rows->map(fn (array $row): ?int => $row['weekAgo']['quantity'] ?? null)),
            'focusCounts' => ['all' => $rows->count()] + collect(self::FOCUSES)
                ->mapWithKeys(fn (string $focus): array => [$focus => $rows->filter(fn (array $row): bool => $this->matchesFocus($row, $focus))->count()])
                ->all(),
        ];
    }

    /** @return array{date: string, amazonInventoryStatus: string, amazonBasisAt: string|null} */
    private function latestSurveyPayload(Survey $survey, CarbonImmutable $latestDate): array
    {
        $skus = fn (): Builder => DB::table('survey_skus as ss')
            ->join('survey_products as sp', 'sp.id', '=', 'ss.survey_product_id')
            ->where('sp.survey_id', $survey->id);
        $hasSavedAmazonInventory = fn (): bool => $survey->resolveHasSavedAmazonInventory(
            fn (): bool => $skus()->where(fn (Builder $query): Builder => $query->where('ss.amazon_own_stock', '>', 0)->orWhere('ss.amazon_fba_stock', '>', 0))->exists(),
        );
        $status = $survey->resolveAmazonInventoryStatus(
            fn (): bool => $skus()
                ->where(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $query): Builder => $query->whereNotNull('ss.child_asin')->whereRaw("TRIM(ss.child_asin) <> ''"))
                    ->orWhereNotNull('ss.amazon_own_stock')
                    ->orWhereNotNull('ss.amazon_fba_stock'))
                ->where(fn (Builder $query): Builder => $query->whereNull('ss.amazon_own_stock')->orWhereNull('ss.amazon_fba_stock'))
                ->exists(),
            $hasSavedAmazonInventory,
        );

        return [
            'date' => $latestDate->toDateString(),
            'amazonInventoryStatus' => $status,
            'amazonBasisAt' => $survey->amazon_source === Survey::AMAZON_SOURCE_SP_API
                ? $survey->amazonImport?->basis_at?->toIso8601String()
                : ($hasSavedAmazonInventory() ? $survey->executed_at->toIso8601String() : null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>|null  $latestSurvey
     * @param  list<string>  $brands
     * @param  list<array{key: string, name: string}>  $groups
     * @return array<string, mixed>
     */
    private function response(array $rows, array $summary, ?array $latestSurvey, int $page, int $total, array $brands, array $groups, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dateQuery = Survey::query()->whereHas('products');
        $firstDate = (clone $dateQuery)->min('executed_at');
        $lastDate = (clone $dateQuery)->max('executed_at');

        return [
            'data' => $rows,
            'summary' => $summary,
            'latestSurvey' => $latestSurvey,
            'meta' => ['page' => $page, 'perPage' => self::PER_PAGE, 'total' => $total],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'brands' => $brands,
            'groups' => $groups,
            'dateRange' => $firstDate !== null && $lastDate !== null ? [
                'from' => CarbonImmutable::parse($firstDate)->toDateString(),
                'to' => CarbonImmutable::parse($lastDate)->toDateString(),
            ] : null,
        ];
    }
}
