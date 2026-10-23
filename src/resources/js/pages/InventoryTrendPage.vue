<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
    exportInventoryTrend,
    fetchInventoryTrend,
    fetchInventoryTrendProducts,
    type InventoryScope,
    type InventoryTrendDailySource,
    type InventoryTrendDetailProduct,
    type InventoryTrendProduct,
    type InventoryTrendSeries,
    type InventoryTrendWarehouseTotal,
} from "@/api/inventoryTrends";
import InventoryTrendChart from "@/components/inventory/InventoryTrendChart.vue";
import InventoryTrendHeatmap from "@/components/inventory/InventoryTrendHeatmap.vue";
import InventoryTrendMiniChart from "@/components/inventory/InventoryTrendMiniChart.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import SegmentedControl from "@/components/ui/SegmentedControl.vue";
import StatCard from "@/components/ui/StatCard.vue";
import StatusLamp from "@/components/ui/StatusLamp.vue";
import { warehousesOfScope } from "@/constants/warehouses";
import { useInventoryTrendListStore } from "@/stores/inventoryTrendList";
import { formatNumber } from "@/utils/format";
import { changeRate, formatMonthDay, formatRate, latestSurveyLamp, productMetrics } from "@/utils/inventoryTrendMetrics";
import {
    addDays,
    INVENTORY_SCOPE_OPTIONS,
    MAXIMUM_PERIOD_DAYS,
    parseInventoryTrendQuery,
    PERIOD_OPTIONS,
    replaceInventoryTrendQuery,
    type InventoryTrendQuery,
    type PeriodPreset,
} from "@/utils/inventoryTrendQuery";

const props = defineProps<{ productCode: string }>();

interface TrendSeries {
    skuCode: string;
    label: string;
    color: string;
    points: { date: string; quantity: number | null }[];
}

interface StockoutSeries {
    label: string;
    color: string;
    points: {
        date: string;
        quantity: number | null;
        skus: { skuCode: string; stockoutDays: number }[];
        periodStockoutDays: number;
    }[];
}

interface SkuSummary {
    skuCode: string;
    size: string;
    points: { date: string; quantity: number | null }[];
    latest: number | null;
    average: number | null;
    minimum: number | null;
    maximum: number | null;
    stockoutDays: number;
}

/** 欠品SKU数は日付の下に黄色の四角と件数で出す。品番全体の棒は倉庫色で積み上げる */
const STOCKOUT_COLOR = "#f5c400";
/** 一覧の条件のうち、在庫推移の条件（在庫区分・期間）以外のキー。一覧のストアの並びが今の一覧のものかを確かめるのに使う */
const LIST_QUERY_KEYS = ["group", "brand", "search", "focus", "sort", "page"];
const route = useRoute();
const router = useRouter();
const listStore = useInventoryTrendListStore();
const products = ref<InventoryTrendProduct[]>([]);
const productsLoaded = ref(false);
const availableRange = ref<{ from: string; to: string } | null>(null);
const productCode = computed(() => props.productCode);
const inventoryScope = ref<InventoryScope>("mallTotal");
const activePreset = ref<PeriodPreset>("30");
const targetStart = ref("");
const targetEnd = ref("");
const rawSeries = ref<InventoryTrendSeries[]>([]);
const dailySources = ref<InventoryTrendDailySource[]>([]);
const warehouseTotals = ref<InventoryTrendWarehouseTotal[]>([]);
const trendProduct = ref<InventoryTrendDetailProduct | null>(null);
const summaryTableOpen = ref(false);
/** 表示中のデータを取得したときの在庫区分（選択を変えてから応答が届くまでの食い違いを防ぐ） */
const loadedScope = ref<InventoryScope>("mallTotal");
const productsLoading = ref(false);
const trendLoading = ref(false);
const productsError = ref("");
const trendError = ref("");
const exportError = ref("");
const exportLoading = ref(false);
let trendRequestId = 0;

const selectedProduct = computed(() => products.value.find((product) => product.productCode === productCode.value));
/** 品番の一覧を読み込めたのに、表示する品番の調査がない（存在しない品番を URL で開いたときなど） */
const productMissing = computed(() => productsLoaded.value && !selectedProduct.value);
const periodOptions = PERIOD_OPTIONS;
const inventoryScopeOptions = INVENTORY_SCOPE_OPTIONS;
/** 見出しの品番の情報。在庫推移の応答が届くまでは、品番の一覧の情報を出す */
const headerProduct = computed(() => {
    const product = trendProduct.value?.productCode === productCode.value ? trendProduct.value : null;
    return {
        brand: product?.brand ?? selectedProduct.value?.brand ?? "",
        category: product?.category ?? selectedProduct.value?.category ?? "",
        skuCount: product?.skuCount ?? selectedProduct.value?.skuCount ?? null,
        colorCount: product?.colorCount ?? null,
        sizeCount: product?.sizeCount ?? null,
        groups: product?.groups ?? [],
    };
});
/** 前後の品番の順番。一覧から開いたときは、その一覧の並び（ストア）。URL を直接開いたときは品番コード順 */
const neighborCodes = computed(() => {
    const fromList = listStore.productCodes.includes(productCode.value) && LIST_QUERY_KEYS.every((key) => (listStore.query[key] ?? null) === (route.query[key] ?? null));
    return fromList ? listStore.productCodes : products.value.map((product) => product.productCode);
});
const previousCode = computed(() => {
    const index = neighborCodes.value.indexOf(productCode.value);
    return index > 0 ? neighborCodes.value[index - 1]! : null;
});
const nextCode = computed(() => {
    const index = neighborCodes.value.indexOf(productCode.value);
    return index >= 0 && index < neighborCodes.value.length - 1 ? neighborCodes.value[index + 1]! : null;
});
const metrics = computed(() => productMetrics(loadedScope.value, dailySources.value, warehouseTotals.value, rawSeries.value));
const totalRate = computed(() => changeRate(metrics.value.latestQuantity, metrics.value.weekAgoQuantity));
const latestSurvey = computed(() => {
    const source = dailySources.value.at(-1);
    return source ? latestSurveyLamp(source) : null;
});
/** 例: 2026/09/24 〜 2026/10/23（30日） */
const targetPeriodLabel = computed(() => {
    if (!targetStart.value || !targetEnd.value) return "";
    return `${targetStart.value.replaceAll("-", "/")} 〜 ${targetEnd.value.replaceAll("-", "/")}（${selectedDates.value.length}日）`;
});
const earliestTargetStart = computed(() => {
    const availableFrom = availableRange.value?.from ?? "";
    if (!targetEnd.value) return availableFrom;
    const limitedFrom = addDays(targetEnd.value, -(MAXIMUM_PERIOD_DAYS - 1));
    return limitedFrom > availableFrom ? limitedFrom : availableFrom;
});
const latestTargetEnd = computed(() => {
    const availableTo = availableRange.value?.to ?? "";
    if (!targetStart.value) return availableTo;
    const limitedTo = addDays(targetStart.value, MAXIMUM_PERIOD_DAYS - 1);
    return !availableTo || limitedTo < availableTo ? limitedTo : availableTo;
});
const selectedDates = computed(() => {
    if (!targetStart.value || !targetEnd.value) return [];
    const dates: string[] = [];
    for (let date = targetStart.value; date <= targetEnd.value; date = addDays(date, 1)) dates.push(date);
    return dates;
});
const productWarehouses = computed(() => warehousesOfScope(loadedScope.value));
/** 品番全体の棒。在庫区分に含まれる倉庫を、倉庫色の固定順で積み上げる */
const productChartSeries = computed<TrendSeries[]>(() => {
    if (rawSeries.value.length === 0) return [];

    const totalsByDate = new Map(warehouseTotals.value.map((total) => [total.date, total]));
    return productWarehouses.value.map((warehouse) => ({
        skuCode: warehouse.key,
        label: warehouse.label,
        color: warehouse.color,
        points: selectedDates.value.map((date) => ({ date, quantity: totalsByDate.get(date)?.[warehouse.key] ?? null })),
    }));
});
/** データはあるが、在庫区分に含まれる倉庫のどれかが取込待ち・未取得の日 */
const productPendingDates = computed(() => {
    const totalsByDate = new Map(warehouseTotals.value.map((total) => [total.date, total]));
    return dailySources.value
        .map((source) => source.date)
        .filter((date) => {
            const total = totalsByDate.get(date);
            return total !== undefined && productWarehouses.value.some((warehouse) => total[warehouse.key] === null);
        });
});
const productStockoutSeries = computed<StockoutSeries | null>(() => {
    if (rawSeries.value.length === 0) return null;

    const completeDates = new Set(
        selectedDates.value.filter((date) => {
            const points = rawSeries.value.flatMap((series) => {
                const point = series.points.find((item) => item.date === date);
                return point?.source == null ? [] : [point];
            });
            return points.length > 0 && points.every((point) => point.quantity !== null);
        }),
    );
    const stockoutDaysBySku = new Map(
        rawSeries.value.map((series) => [series.skuCode, series.points.filter((point) => completeDates.has(point.date) && point.source !== null && point.quantity === 0).length]),
    );
    const periodStockoutDays = [...stockoutDaysBySku.values()].reduce((total, days) => total + days, 0);

    return {
        label: "欠品SKU数",
        color: STOCKOUT_COLOR,
        points: selectedDates.value.map((date) => {
            const availableSeries = rawSeries.value.flatMap((series) => {
                const point = series.points.find((item) => item.date === date);
                return point?.source == null || point.quantity === null ? [] : [{ series, quantity: point.quantity }];
            });
            if (!completeDates.has(date)) {
                return { date, quantity: null, skus: [], periodStockoutDays };
            }
            const stockoutSkus = availableSeries
                .filter(({ quantity }) => quantity === 0)
                .map(({ series }) => ({
                    skuCode: series.skuCode,
                    stockoutDays: stockoutDaysBySku.get(series.skuCode) ?? 0,
                }));

            return {
                date,
                quantity: stockoutSkus.length,
                skus: stockoutSkus,
                periodStockoutDays,
            };
        }),
    };
});
/** 例: 2026-10-03 → 10-16（14 日間）。年をまたぐときは終了日も年から書く */
const summaryPeriodLabel = computed(() => {
    if (!targetStart.value || !targetEnd.value) return "";
    const end = targetStart.value.slice(0, 4) === targetEnd.value.slice(0, 4) ? targetEnd.value.slice(5) : targetEnd.value;
    return `${targetStart.value} → ${end}（${selectedDates.value.length} 日間）`;
});
const skuSummaries = computed<SkuSummary[]>(() =>
    rawSeries.value.map((series) => {
        const quantities = series.points.flatMap((point) => (point.quantity === null ? [] : [point.quantity]));
        return {
            skuCode: series.skuCode,
            size: series.size,
            points: series.points,
            latest: quantities.at(-1) ?? null,
            average: quantities.length > 0 ? quantities.reduce((sum, quantity) => sum + quantity, 0) / quantities.length : null,
            minimum: quantities.length > 0 ? Math.min(...quantities) : null,
            maximum: quantities.length > 0 ? Math.max(...quantities) : null,
            stockoutDays: quantities.filter((quantity) => quantity === 0).length,
        };
    }),
);
onMounted(() => {
    void loadProducts();
});

async function loadProducts() {
    productsLoading.value = true;
    productsError.value = "";
    try {
        const response = await fetchInventoryTrendProducts();
        products.value = response.data;
        availableRange.value = response.dateRange;
        applyQuery(parseInventoryTrendQuery(route.query));
        productsLoaded.value = true;

        if (selectedProduct.value) await loadTrend();
    } catch {
        productsError.value = "対象品番の読み込みに失敗しました。時間をおいて再度お試しください。";
    } finally {
        productsLoading.value = false;
    }
}

async function loadTrend() {
    if (!productCode.value || !targetStart.value || !targetEnd.value) return;
    const requestId = ++trendRequestId;
    trendLoading.value = true;
    trendError.value = "";
    try {
        const response = await fetchInventoryTrend({
            productCode: productCode.value,
            from: targetStart.value,
            to: targetEnd.value,
            scope: inventoryScope.value,
        });
        if (requestId !== trendRequestId) return;
        rawSeries.value = response.series;
        trendProduct.value = response.product;
        dailySources.value = response.dailySources;
        warehouseTotals.value = response.warehouseTotals;
        loadedScope.value = response.scope;
    } catch {
        if (requestId !== trendRequestId) return;
        rawSeries.value = [];
        trendProduct.value = null;
        dailySources.value = [];
        warehouseTotals.value = [];
        trendError.value = "在庫推移の読み込みに失敗しました。時間をおいて再度お試しください。";
    } finally {
        if (requestId === trendRequestId) trendLoading.value = false;
    }
}

/** URL のクエリの在庫区分と期間を画面に入れる。期間指定でないときは、最新の調査日までの期間にする */
function applyQuery(query: InventoryTrendQuery) {
    inventoryScope.value = query.scope;
    const range = availableRange.value;
    if (!range) return;

    if (query.period === "custom" && query.from && query.to) {
        targetStart.value = query.from < range.from ? range.from : query.from;
        targetEnd.value = query.to > range.to ? range.to : query.to;
        if (targetStart.value > targetEnd.value) targetStart.value = targetEnd.value;
        const minimumStart = addDays(targetEnd.value, -(MAXIMUM_PERIOD_DAYS - 1));
        if (targetStart.value < minimumStart) targetStart.value = minimumStart;
        activePreset.value = "custom";
        return;
    }

    const days = query.period === "custom" ? 30 : Number(query.period);
    targetEnd.value = range.to;
    const candidateStart = addDays(range.to, -(days - 1));
    targetStart.value = candidateStart < range.from ? range.from : candidateStart;
    activePreset.value = query.period === "custom" ? "30" : query.period;
}

function currentQuery(): InventoryTrendQuery {
    return {
        scope: inventoryScope.value,
        period: activePreset.value,
        from: activePreset.value === "custom" ? targetStart.value : null,
        to: activePreset.value === "custom" ? targetEnd.value : null,
    };
}

/** 在庫区分と期間を URL のクエリに残す（一覧と行き来しても、再読み込みしても保つ） */
function syncQuery() {
    void router.replace({ query: replaceInventoryTrendQuery(route.query, currentQuery()) });
}

function backToList() {
    // 一覧から開いたときは、一覧の条件（グループ・並び順・ページなど）もクエリに入っている
    void router.push({ name: "inventory-trends", query: replaceInventoryTrendQuery(route.query, currentQuery()) });
}

/** 前後の品番に移る。在庫区分と表示期間、一覧の条件はそのまま */
function goToProduct(code: string | null) {
    if (!code) return;
    void router.push({ name: "inventory-trend", params: { productCode: code }, query: replaceInventoryTrendQuery(route.query, currentQuery()) });
}

watch(
    () => props.productCode,
    () => {
        if (!productsLoaded.value) return;
        if (selectedProduct.value) {
            void loadTrend();
            return;
        }
        rawSeries.value = [];
        trendProduct.value = null;
        dailySources.value = [];
        warehouseTotals.value = [];
    },
);

/** SKU別サマリーの数値。デザインどおり単位は付けない */
function formatSummaryNumber(quantity: number | null): string {
    return quantity === null ? "—" : String(quantity);
}

function formatTableAverage(quantity: number | null): string {
    return quantity === null ? "—" : quantity.toFixed(1);
}

/** 在庫数の数字。zeroBadge の列（最新在庫・期間最小）は 0 を淡い縞の札にする。値がなければ弱い「—」 */
function summaryNumberClass(quantity: number | null, zeroBadge = false): string {
    if (quantity === null) return "font-num text-lg text-muted";
    if (zeroBadge && quantity === 0)
        return "inline-block min-w-8 rounded-chip bg-hazard-pale px-2 text-center font-num text-[21px] leading-[1.15] font-semibold shadow-[inset_0_0_0_1px_rgb(185_140_0/0.25)]";
    return "font-num text-[21px] leading-[1.15] font-semibold";
}

function selectPreset(preset: "14" | "30" | "90") {
    if (!targetEnd.value) return;
    activePreset.value = preset;
    const days = Number(preset);
    const start = addDays(targetEnd.value, -(days - 1));
    targetStart.value = availableRange.value && start < availableRange.value.from ? availableRange.value.from : start;
    syncQuery();
    void loadTrend();
}

function updatePeriodPreset(value: PeriodPreset) {
    if (value === "custom") {
        activePreset.value = "custom";
        syncQuery();
    } else selectPreset(value);
}

function updateInventoryScope(value: InventoryScope) {
    inventoryScope.value = value;
    syncQuery();
    void loadTrend();
}

function updateTargetStart(event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.value) {
        input.value = targetStart.value;
        return;
    }
    targetStart.value = input.value;
    if (!targetEnd.value || targetStart.value > targetEnd.value) {
        targetEnd.value = targetStart.value;
    } else {
        const maximumEnd = addDays(targetStart.value, MAXIMUM_PERIOD_DAYS - 1);
        if (targetEnd.value > maximumEnd) targetEnd.value = maximumEnd;
    }
    activePreset.value = "custom";
    syncQuery();
    void loadTrend();
}

function updateTargetEnd(event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.value) {
        input.value = targetEnd.value;
        return;
    }
    targetEnd.value = input.value;
    if (!targetStart.value || targetEnd.value < targetStart.value) {
        targetStart.value = targetEnd.value;
    } else {
        const minimumStart = addDays(targetEnd.value, -(MAXIMUM_PERIOD_DAYS - 1));
        if (targetStart.value < minimumStart) targetStart.value = minimumStart;
    }
    activePreset.value = "custom";
    syncQuery();
    void loadTrend();
}

/** この品番の日次在庫CSV（対象期間の調査日の在庫と、調査がない日の Amazon 取得結果） */
async function exportCsv() {
    if (!targetStart.value || !targetEnd.value || !productCode.value) return;
    exportLoading.value = true;
    exportError.value = "";
    try {
        const blob = await exportInventoryTrend({ productCode: productCode.value, from: targetStart.value, to: targetEnd.value });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = `${productCode.value}_${targetStart.value}_${targetEnd.value}_日次在庫.csv`;
        link.click();
        URL.revokeObjectURL(url);
    } catch {
        exportError.value = "日次在庫のCSV出力に失敗しました。時間をおいて再度お試しください。";
    } finally {
        exportLoading.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <BaseButton data-testid="back-to-list" variant="ghost" size="sm" icon="chevron_left" @click="backToList">品番一覧に戻る</BaseButton>
            <StatusLamp v-if="latestSurvey" data-testid="latest-survey-status" class="text-[11.5px] text-ink-2" :state="latestSurvey.state" :label="latestSurvey.label" :detail="latestSurvey.detail" />
        </div>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h1 class="font-mono text-[26px] leading-tight font-semibold uppercase">{{ productCode }}</h1>
                    <p v-if="selectedProduct || trendProduct" data-testid="product-detail" class="text-[12.5px] text-ink-2">
                        {{ headerProduct.brand }} · {{ headerProduct.category }}<template v-if="headerProduct.skuCount !== null"> · {{ headerProduct.skuCount }} SKU</template
                        ><template v-if="headerProduct.colorCount !== null && headerProduct.sizeCount !== null"
                            >（{{ headerProduct.colorCount }}カラー × {{ headerProduct.sizeCount }}サイズ）</template
                        >
                    </p>
                </div>
                <div v-if="headerProduct.groups.length > 0" class="mt-1.5 flex flex-wrap gap-1" aria-label="品番グループ">
                    <span
                        v-for="item in headerProduct.groups"
                        :key="item.key"
                        data-product-group
                        class="rounded-chip border px-1.5 py-px text-[10.5px] leading-4"
                        :class="item.key === 'ungrouped' ? 'border-dashed border-line-stronger text-muted' : 'border-transparent bg-tint text-ink-2'"
                        >{{ item.name }}</span
                    >
                </div>
            </div>
            <div v-if="!productMissing" class="flex flex-wrap items-center gap-2">
                <div class="inline-flex" role="group" aria-label="前後の品番">
                    <BaseButton
                        data-testid="previous-product"
                        class="rounded-r-none"
                        variant="secondary"
                        size="sm"
                        icon="chevron_left"
                        :disabled="!previousCode"
                        :title="previousCode ? `前の品番 ${previousCode}` : '前の品番はありません'"
                        @click="goToProduct(previousCode)"
                    >
                        前の品番<span v-if="previousCode" class="ml-1 font-mono text-[11px] text-muted uppercase">{{ previousCode }}</span>
                    </BaseButton>
                    <BaseButton
                        data-testid="next-product"
                        class="-ml-px rounded-l-none"
                        variant="secondary"
                        size="sm"
                        :disabled="!nextCode"
                        :title="nextCode ? `次の品番 ${nextCode}` : '次の品番はありません'"
                        @click="goToProduct(nextCode)"
                    >
                        次の品番<span v-if="nextCode" class="ml-1 font-mono text-[11px] text-muted uppercase">{{ nextCode }}</span
                        ><AppIcon name="chevron_right" :size="16" class="ml-0.5" />
                    </BaseButton>
                </div>
                <BaseButton
                    data-testid="inventory-trend-export"
                    variant="secondary"
                    size="sm"
                    icon="download"
                    title="対象期間の調査日の在庫と、調査がない日のAmazon取得結果を出力します。Amazon取得だけの日は他の在庫区分が空欄です。"
                    :loading="exportLoading"
                    :disabled="!targetStart || !targetEnd || trendLoading || exportLoading"
                    @click="exportCsv"
                >
                    この品番のCSV
                </BaseButton>
            </div>
        </div>

        <BaseAlert v-if="exportError" tone="danger" title="日次在庫を出力できませんでした">{{ exportError }}</BaseAlert>

        <BaseAlert v-if="productsError" tone="danger" title="対象品番を読み込めませんでした">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ productsError }}</span>
                <BaseButton size="sm" variant="secondary" icon="refresh" @click="() => loadProducts()">再試行</BaseButton>
            </div>
        </BaseAlert>

        <BaseEmpty v-if="productMissing" icon="search_off" title="この品番の在庫推移はありません" description="品番一覧から、調査のある品番を選んでください。">
            <BaseButton variant="primary" icon="chevron_left" @click="backToList">品番一覧に戻る</BaseButton>
        </BaseEmpty>

        <BaseCard v-if="!productMissing">
            <div class="flex flex-wrap items-end gap-x-5 gap-y-3">
                <div>
                    <span class="mb-1 block text-[11px] font-medium text-ink-2">在庫区分</span>
                    <div class="flex h-8 items-center" data-testid="inventory-scope-select">
                        <SegmentedControl :model-value="inventoryScope" :options="inventoryScopeOptions" label="在庫区分" @update:model-value="updateInventoryScope" />
                    </div>
                </div>
                <div>
                    <span class="mb-1 block text-[11px] font-medium text-ink-2">期間</span>
                    <div class="flex h-8 items-center" data-testid="period-select">
                        <SegmentedControl :model-value="activePreset" :options="periodOptions" label="期間" @update:model-value="updatePeriodPreset" />
                    </div>
                </div>
                <div v-if="activePreset === 'custom'" class="min-w-0">
                    <span class="mb-1 block text-[11px] font-medium text-ink-2">対象期間</span>
                    <div
                        class="flex h-8 items-center gap-1.5 rounded-control border border-line-strong bg-surface px-1.5 font-mono text-[11.5px] text-ink shadow-field focus-within:border-ink-2 focus-within:ring-3 focus-within:ring-ink/10"
                        :title="`対象期間は最大${MAXIMUM_PERIOD_DAYS}日間です`"
                    >
                        <input
                            type="date"
                            :value="targetStart"
                            :min="earliestTargetStart"
                            :max="availableRange?.to"
                            :disabled="!availableRange"
                            aria-label="対象期間の開始日"
                            class="h-7 min-w-0 bg-transparent px-1 focus:outline-none"
                            @change="updateTargetStart"
                        />
                        <span class="text-faint" aria-hidden="true">→</span>
                        <input
                            type="date"
                            :value="targetEnd"
                            :min="availableRange?.from"
                            :max="latestTargetEnd"
                            :disabled="!availableRange"
                            aria-label="対象期間の終了日"
                            class="h-7 min-w-0 bg-transparent px-1 focus:outline-none"
                            @change="updateTargetEnd"
                        />
                    </div>
                </div>
                <p v-if="targetPeriodLabel" data-testid="target-period" class="flex h-8 items-center font-mono text-[11.5px] text-ink-2 sm:ml-auto">{{ targetPeriodLabel }}</p>
            </div>
        </BaseCard>

        <section
            v-if="!productMissing && !trendLoading && !trendError && metrics.latestDate"
            aria-label="品番の数値"
            class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-3"
            data-testid="product-metrics"
        >
            <StatCard
                data-metric="total"
                :title="`在庫総数（${formatMonthDay(metrics.latestDate)}）`"
                :value="metrics.latestQuantity === null ? null : formatNumber(metrics.latestQuantity)"
                unit="点"
                :note="
                    metrics.latestQuantity === null
                        ? '未確定の倉庫があります'
                        : metrics.weekAgoQuantity === null
                          ? '前週と比べられません'
                          : `前週比 ${formatRate(totalRate)}（${formatNumber(metrics.weekAgoQuantity)}点から）`
                "
            />
            <StatCard
                data-metric="average"
                title="期間の平均"
                :value="metrics.average === null ? null : formatNumber(metrics.average)"
                unit="点"
                :note="metrics.average === null ? '未確定の倉庫があります' : `確定した ${metrics.confirmedDays}日の平均`"
            />
            <StatCard
                data-metric="stockout"
                :title="`欠品SKU（${formatMonthDay(metrics.latestDate)}）`"
                unit="SKU"
                :note="metrics.weekAgoStockoutSkuCount === null ? '1週間前と比べられません' : `1週間前は ${metrics.weekAgoStockoutSkuCount} SKU`"
                :alert="metrics.stockoutSkuCount > 0"
            >
                <template #value>
                    <span class="font-num text-[36px] leading-[1.05] font-semibold tabular-nums">{{ metrics.stockoutSkuCount }}</span>
                    <span class="font-num text-[20px] font-semibold text-muted tabular-nums">/ {{ metrics.skuCount }}</span>
                </template>
            </StatCard>
            <StatCard
                data-metric="stockout-days"
                title="期間中の欠品"
                :value="formatNumber(metrics.stockoutSkuDays)"
                unit="SKU・日"
                :note="`欠品したSKU ${metrics.stockoutSkus}件`"
                :alert="metrics.stockoutSkuDays > 0"
            />
        </section>

        <BaseCard v-if="!productMissing" title="倉庫別の在庫（日別）" description="棒の高さが品番全体の在庫数。下の黄色の札は、その日に欠品していたSKUの数です。">
            <template #actions>
                <BaseButton data-testid="refresh-trend" size="sm" variant="ghost" icon="refresh" :loading="trendLoading" @click="() => loadTrend()">再読込</BaseButton>
            </template>

            <p v-if="loadedScope === 'mallTotal' || loadedScope === 'grandTotal'" data-testid="survey-scope-note" class="mb-2 text-[11.5px] text-muted">
                この区分は調査日の保存済み在庫を合算します。未確定の倉庫がある日は、確定した倉庫だけを積み上げ、「未確定」の印を付けます（合計は出しません）。
            </p>

            <div v-if="rawSeries.length > 0" data-testid="product-chart-legend" class="mb-1 flex flex-wrap items-center gap-x-3.5 gap-y-1 pt-1 pb-0.5 text-[11px] text-ink-2">
                <span v-for="warehouse in productWarehouses" :key="warehouse.key" :data-legend-warehouse="warehouse.key" class="inline-flex items-center gap-2"
                    ><span class="h-2.5 w-2.5 rounded-xs" :style="{ backgroundColor: warehouse.color }"></span>{{ warehouse.label }}</span
                >
                <span v-if="productPendingDates.length > 0" data-legend-pending class="inline-flex items-center gap-2"
                    ><span class="h-2.5 w-2.5 rounded-xs border border-dashed border-ink-2"></span>未確定</span
                >
                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-[2px] bg-signal"></span>欠品SKU数</span>
            </div>

            <div v-if="trendLoading" class="flex min-h-80 items-center justify-center gap-2 text-[12.5px] text-muted" role="status">
                <AppIcon name="progress_activity" :size="22" class="animate-spin text-ink-2" />
                在庫推移を読み込んでいます...
            </div>
            <BaseAlert v-else-if="trendError" tone="danger" title="在庫推移を読み込めませんでした">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span>{{ trendError }}</span>
                    <BaseButton size="sm" variant="secondary" icon="refresh" @click="() => loadTrend()">再試行</BaseButton>
                </div>
            </BaseAlert>
            <BaseEmpty v-else-if="rawSeries.length === 0" icon="show_chart" title="対象期間の在庫データがありません" description="対象期間を変更するか、品番一覧から別の品番を選んでください。">
                <BaseButton variant="secondary" icon="chevron_left" @click="backToList">品番一覧に戻る</BaseButton>
            </BaseEmpty>
            <div v-else class="overflow-x-auto pb-1">
                <InventoryTrendChart :series="productChartSeries" :stockout-series="productStockoutSeries" :pending-dates="productPendingDates" />
            </div>
        </BaseCard>

        <BaseCard v-if="!productMissing && rawSeries.length > 0 && !trendLoading && !trendError">
            <InventoryTrendHeatmap v-model:table-open="summaryTableOpen" :product-code="productCode" :series="rawSeries" :dates="selectedDates" :scope="loadedScope">
                <template #table>
                    <p class="px-[18px] pt-3 text-[11.5px] text-muted" data-testid="sku-summary-period">SKU別サマリー（{{ summaryPeriodLabel }}）</p>
                    <div class="overflow-x-auto">
                        <table data-testid="sku-summary-table" class="w-full min-w-180 table-fixed border-collapse tabular-nums">
                            <colgroup>
                                <col class="w-[200px]" />
                                <col class="w-[150px]" />
                                <col />
                                <col />
                                <col />
                                <col />
                                <col />
                            </colgroup>
                            <thead class="text-[11px] whitespace-nowrap text-muted">
                                <tr class="border-b border-line">
                                    <th class="px-3 pt-[9px] pb-[7px] text-left font-semibold">SKU</th>
                                    <th class="px-3 pt-[9px] pb-[7px] text-left font-semibold">推移</th>
                                    <th class="px-3 pt-[9px] pb-[7px] text-right font-semibold">最新在庫</th>
                                    <th class="px-3 pt-[9px] pb-[7px] text-right font-semibold">期間平均</th>
                                    <th class="px-3 pt-[9px] pb-[7px] text-right font-semibold">期間最小</th>
                                    <th class="px-3 pt-[9px] pb-[7px] text-right font-semibold">期間最大</th>
                                    <th class="px-3 pt-[9px] pb-[7px] text-right font-semibold">欠品日数</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="summary in skuSummaries" :key="summary.skuCode" :data-summary-sku="summary.skuCode" class="border-t border-line-2 first:border-t-0">
                                    <td class="py-[5px] pr-3 pl-[18px] text-left font-mono text-xs font-medium text-ink uppercase">
                                        <span class="block truncate" :title="summary.skuCode">{{ summary.skuCode }}</span>
                                    </td>
                                    <td data-summary-field="trend" class="px-3 py-[5px]"><InventoryTrendMiniChart :points="summary.points" /></td>
                                    <td data-summary-field="latest" class="px-3 py-[5px] text-right">
                                        <span :class="summaryNumberClass(summary.latest, true)">{{ formatSummaryNumber(summary.latest) }}</span>
                                    </td>
                                    <td data-summary-field="average" class="px-3 py-[5px] text-right">
                                        <span :class="summaryNumberClass(summary.average)">{{ formatTableAverage(summary.average) }}</span>
                                    </td>
                                    <td data-summary-field="minimum" class="px-3 py-[5px] text-right">
                                        <span :class="summaryNumberClass(summary.minimum, true)">{{ formatSummaryNumber(summary.minimum) }}</span>
                                    </td>
                                    <td data-summary-field="maximum" class="px-3 py-[5px] text-right">
                                        <span :class="summaryNumberClass(summary.maximum)">{{ formatSummaryNumber(summary.maximum) }}</span>
                                    </td>
                                    <td data-summary-field="stockout-days" class="px-3 py-[5px] text-right">
                                        <span
                                            v-if="summary.stockoutDays > 0"
                                            class="inline-block min-w-7 rounded-chip bg-signal px-[7px] text-center font-num text-base leading-[1.6] font-bold text-ink"
                                            >{{ summary.stockoutDays }}</span
                                        >
                                        <span v-else class="font-num text-[21px] leading-[1.15] font-semibold">0</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </InventoryTrendHeatmap>
        </BaseCard>
    </div>
</template>
