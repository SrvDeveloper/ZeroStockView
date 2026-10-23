<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
    exportInventoryTrend,
    fetchInventoryTrend,
    fetchInventoryTrendProducts,
    type InventoryScope,
    type InventoryTrendDailySource,
    type InventoryTrendProduct,
    type InventoryTrendSeries,
    type InventoryTrendWarehouseTotal,
} from "@/api/inventoryTrends";
import InventoryTrendChart from "@/components/inventory/InventoryTrendChart.vue";
import InventoryTrendMiniChart from "@/components/inventory/InventoryTrendMiniChart.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import SegmentedControl from "@/components/ui/SegmentedControl.vue";
import { warehousesOfScope } from "@/constants/warehouses";
import type { SelectOption } from "@/types";
import { parseInventoryTrendQuery, replaceInventoryTrendQuery, type InventoryTrendQuery, type PeriodPreset } from "@/utils/inventoryTrendQuery";

const props = defineProps<{ productCode: string }>();

interface TrendSeries {
    skuCode: string;
    label: string;
    color: string;
    points: { date: string; quantity: number | null }[];
}

type TrendView = "sku" | "product";

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

interface SkuCard {
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
const route = useRoute();
const router = useRouter();
const products = ref<InventoryTrendProduct[]>([]);
const productsLoaded = ref(false);
const availableRange = ref<{ from: string; to: string } | null>(null);
const productCode = computed(() => props.productCode);
const inventoryScope = ref<InventoryScope>("mallTotal");
const trendView = ref<TrendView>("product");
const MAXIMUM_PERIOD_DAYS = 365;
const activePreset = ref<PeriodPreset>("30");
const targetStart = ref("");
const targetEnd = ref("");
const rawSeries = ref<InventoryTrendSeries[]>([]);
const dailySources = ref<InventoryTrendDailySource[]>([]);
const warehouseTotals = ref<InventoryTrendWarehouseTotal[]>([]);
/** 表示中のデータを取得したときの在庫区分（選択を変えてから応答が届くまでの食い違いを防ぐ） */
const loadedScope = ref<InventoryScope>("mallTotal");
const productsLoading = ref(false);
const trendLoading = ref(false);
const productsError = ref("");
const trendError = ref("");
const exportError = ref("");
const exportLoading = ref<"selected" | "all" | null>(null);
const exportMenuOpen = ref(false);
const exportMenuRef = ref<HTMLElement | null>(null);
let trendRequestId = 0;

const selectedProduct = computed(() => products.value.find((product) => product.productCode === productCode.value));
/** 品番の一覧を読み込めたのに、表示する品番の調査がない（存在しない品番を URL で開いたときなど） */
const productMissing = computed(() => productsLoaded.value && !selectedProduct.value);
const periodOptions: { value: PeriodPreset; label: string }[] = [
    { value: "14", label: "2週間" },
    { value: "30", label: "1か月" },
    { value: "90", label: "3か月" },
    { value: "custom", label: "期間指定" },
];
const inventoryScopeOptions: SelectOption[] = [
    { value: "mallTotal", label: "モール全体" },
    { value: "amazon", label: "Amazon" },
    { value: "boss", label: "BOSS" },
    { value: "free", label: "フリー" },
    { value: "stock", label: "ストック" },
    { value: "grandTotal", label: "総数" },
];
const selectedInventoryScopeLabel = computed(() => inventoryScopeOptions.find((option) => option.value === inventoryScope.value)?.label ?? "");
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
    if (trendView.value !== "product" || rawSeries.value.length === 0) return null;

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
const chartTitle = computed(() => (trendView.value === "sku" ? "SKU別 在庫数の推移" : `${productCode.value.toUpperCase()} の倉庫別在庫`));
const chartDescription = computed(() =>
    trendView.value === "sku"
        ? `${selectedInventoryScopeLabel.value}の在庫数を、対象品番の全SKUについて1日単位で表示しています。`
        : "各日の最後の調査結果を集計しています。棒にマウスを置くと内訳を表示します。",
);
/** 例: 2026-10-03 → 10-16（14 日間）。年をまたぐときは終了日も年から書く */
const summaryPeriodLabel = computed(() => {
    if (!targetStart.value || !targetEnd.value) return "";
    const end = targetStart.value.slice(0, 4) === targetEnd.value.slice(0, 4) ? targetEnd.value.slice(5) : targetEnd.value;
    return `${targetStart.value} → ${end}（${selectedDates.value.length} 日間）`;
});
const skuCards = computed<SkuCard[]>(() =>
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
    document.addEventListener("click", closeExportMenuOnOutsideClick);
    document.addEventListener("keydown", closeExportMenuOnEscape);
    void loadProducts();
});

onBeforeUnmount(() => {
    document.removeEventListener("click", closeExportMenuOnOutsideClick);
    document.removeEventListener("keydown", closeExportMenuOnEscape);
});

function closeExportMenuOnOutsideClick(event: MouseEvent) {
    if (exportMenuOpen.value && exportMenuRef.value && !exportMenuRef.value.contains(event.target as Node)) exportMenuOpen.value = false;
}

function closeExportMenuOnEscape(event: KeyboardEvent) {
    if (event.key === "Escape") exportMenuOpen.value = false;
}

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
        dailySources.value = response.dailySources;
        warehouseTotals.value = response.warehouseTotals;
        loadedScope.value = response.scope;
    } catch {
        if (requestId !== trendRequestId) return;
        rawSeries.value = [];
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
    void router.push({ name: "inventory-trends", query: replaceInventoryTrendQuery({}, currentQuery()) });
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
        dailySources.value = [];
        warehouseTotals.value = [];
    },
);

function addDays(date: string, amount: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + amount);
    return value.toISOString().slice(0, 10);
}

function formatSummaryQuantity(quantity: number | null): string {
    return quantity === null ? "—" : `${quantity}点`;
}

/** SKU別サマリーの数値。デザインどおり単位は付けない */
function formatSummaryNumber(quantity: number | null): string {
    return quantity === null ? "—" : String(quantity);
}

function formatSummaryAverage(quantity: number | null): string {
    if (quantity === null) return "—";
    return `${Number(quantity.toFixed(1))}点`;
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

function updateInventoryScope(value: string | number | null) {
    if (value !== "mallTotal" && value !== "amazon" && value !== "boss" && value !== "free" && value !== "stock" && value !== "grandTotal") return;
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

async function exportCsv(scope: "selected" | "all") {
    if (!targetStart.value || !targetEnd.value || (scope === "selected" && !productCode.value)) return;
    exportMenuOpen.value = false;
    exportLoading.value = scope;
    exportError.value = "";
    try {
        const blob = await exportInventoryTrend({
            ...(scope === "selected" ? { productCode: productCode.value } : {}),
            from: targetStart.value,
            to: targetEnd.value,
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        const filePrefix = scope === "selected" ? productCode.value : "全品番";
        link.download = `${filePrefix}_${targetStart.value}_${targetEnd.value}_日次在庫.csv`;
        link.click();
        URL.revokeObjectURL(url);
    } catch {
        exportError.value = "日次在庫のCSV出力に失敗しました。時間をおいて再度お試しください。";
    } finally {
        exportLoading.value = null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <BaseButton data-testid="back-to-list" class="self-start" variant="ghost" size="sm" icon="chevron_left" @click="backToList">品番一覧に戻る</BaseButton>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="font-mono text-[22px] leading-tight font-semibold uppercase">{{ productCode }}</h1>
                <p v-if="selectedProduct" class="mt-1 text-[12.5px] text-ink-2">{{ selectedProduct.brand }} ・ {{ selectedProduct.category }} ・ {{ selectedProduct.skuCount }} SKU</p>
            </div>
            <div ref="exportMenuRef" class="relative">
                <BaseButton
                    data-testid="inventory-trend-export-button"
                    variant="primary"
                    icon="download"
                    aria-haspopup="menu"
                    :aria-expanded="exportMenuOpen"
                    :loading="exportLoading !== null"
                    :disabled="!targetStart || !targetEnd || trendLoading || exportLoading !== null"
                    @click="exportMenuOpen = !exportMenuOpen"
                >
                    日次在庫をCSV出力
                </BaseButton>
                <div v-if="exportMenuOpen" class="absolute right-0 z-40 mt-1.5 w-72 overflow-hidden rounded-card border border-line bg-surface py-1 shadow-float" role="menu">
                    <button
                        type="button"
                        data-testid="inventory-trend-export"
                        role="menuitem"
                        class="block w-full px-3.5 py-2 text-left text-[12.5px] text-ink hover:bg-surface-2 disabled:cursor-not-allowed disabled:text-faint disabled:hover:bg-transparent"
                        :disabled="!productCode"
                        @click="exportCsv('selected')"
                    >
                        選択品番の日次CSV<span v-if="productCode" class="ml-2 font-mono text-[11px] text-muted uppercase">{{ productCode }}</span>
                    </button>
                    <button
                        type="button"
                        data-testid="inventory-trend-export-all"
                        role="menuitem"
                        class="block w-full px-3.5 py-2 text-left text-[12.5px] text-ink hover:bg-surface-2"
                        @click="exportCsv('all')"
                    >
                        全品番の日次CSV
                    </button>
                    <p class="mt-1 border-t border-line-2 px-3.5 pt-2 pb-1.5 text-[11px] leading-relaxed whitespace-normal text-muted">
                        対象期間の調査日の在庫と、調査がない日のAmazon取得結果を出力します。Amazon取得だけの日は他の在庫区分が空欄です。
                    </p>
                </div>
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
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1.2fr)] lg:items-end">
                <BaseSelect
                    :model-value="inventoryScope"
                    data-testid="inventory-scope-select"
                    :options="inventoryScopeOptions"
                    label="在庫区分"
                    :show-placeholder="false"
                    @update:model-value="updateInventoryScope"
                />
                <div>
                    <span class="mb-1 block text-[11px] font-medium text-ink-2">表示期間</span>
                    <div class="flex h-8 items-center" data-testid="period-select">
                        <SegmentedControl :model-value="activePreset" :options="periodOptions" label="表示期間" @update:model-value="updatePeriodPreset" />
                    </div>
                </div>
                <div class="min-w-0">
                    <span class="mb-1 block text-[11px] font-medium text-ink-2">期間</span>
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
                            class="h-7 min-w-0 flex-1 bg-transparent px-1 focus:outline-none"
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
                            class="h-7 min-w-0 flex-1 bg-transparent px-1 focus:outline-none"
                            @change="updateTargetEnd"
                        />
                    </div>
                </div>
            </div>
        </BaseCard>

        <BaseCard v-if="!productMissing" :title="chartTitle" :description="chartDescription">
            <template #actions>
                <BaseButton data-testid="refresh-trend" size="sm" variant="ghost" icon="refresh" :loading="trendLoading" @click="() => loadTrend()">再読込</BaseButton>
                <div class="inline-flex gap-0.5 rounded-control bg-tint p-0.5" role="group" aria-label="在庫推移の確認単位">
                    <button
                        type="button"
                        data-testid="trend-view-product"
                        class="rounded-chip px-3 py-[3px] text-[11.5px] transition-colors"
                        :class="trendView === 'product' ? 'bg-surface font-semibold text-ink shadow-raised' : 'text-ink-2 hover:cursor-pointer hover:text-ink'"
                        :aria-pressed="trendView === 'product'"
                        @click="trendView = 'product'"
                    >
                        品番全体
                    </button>
                    <button
                        type="button"
                        data-testid="trend-view-sku"
                        class="rounded-chip px-3 py-[3px] text-[11.5px] transition-colors"
                        :class="trendView === 'sku' ? 'bg-surface font-semibold text-ink shadow-raised' : 'text-ink-2 hover:cursor-pointer hover:text-ink'"
                        :aria-pressed="trendView === 'sku'"
                        @click="trendView = 'sku'"
                    >
                        SKU別
                    </button>
                </div>
            </template>

            <p v-if="trendView === 'product' && (loadedScope === 'mallTotal' || loadedScope === 'grandTotal')" data-testid="survey-scope-note" class="mb-2 text-[11.5px] text-muted">
                この区分は調査日の保存済み在庫を合算します。未確定の倉庫がある日は、確定した倉庫だけを積み上げ、「未確定」の印を付けます（合計は出しません）。
            </p>

            <div
                v-if="rawSeries.length > 0 && trendView === 'product'"
                data-testid="product-chart-legend"
                class="mb-1 flex flex-wrap items-center gap-x-3.5 gap-y-1 pt-1 pb-0.5 text-[11px] text-ink-2"
            >
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
            <div v-else-if="trendView === 'sku'" data-testid="sku-chart-grid" class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,230px),1fr))] gap-3">
                <article
                    v-for="sku in skuCards"
                    :key="sku.skuCode"
                    :data-sku-card="sku.skuCode"
                    class="min-w-0 rounded-card border bg-surface p-3 shadow-raised"
                    :class="sku.stockoutDays > 0 ? 'border-signal-border' : 'border-line'"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="truncate font-mono text-xs font-semibold text-ink uppercase" :title="sku.skuCode">{{ sku.skuCode }}</h3>
                            <p v-if="sku.size" class="mt-0.5 text-[11px] text-muted">サイズ {{ sku.size }}</p>
                        </div>
                        <span
                            class="shrink-0 rounded-chip px-1.5 py-px text-[10.5px] font-semibold"
                            :class="sku.latest === null ? 'bg-tint text-ink-2' : sku.stockoutDays > 0 ? 'bg-signal text-ink' : 'bg-ok-soft text-ok-ink'"
                        >
                            {{ sku.latest === null ? "データなし" : sku.stockoutDays > 0 ? `欠品 ${sku.stockoutDays}日` : "在庫あり" }}
                        </span>
                    </div>
                    <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[11px] text-muted">
                        <span
                            >最新 <strong class="ml-1 font-num text-lg font-semibold text-ink">{{ formatSummaryQuantity(sku.latest) }}</strong></span
                        >
                        <span
                            >期間平均 <strong class="ml-1 font-num text-sm font-semibold text-ink-2">{{ formatSummaryAverage(sku.average) }}</strong></span
                        >
                    </div>
                    <InventoryTrendMiniChart :label="sku.skuCode" :points="sku.points" />
                </article>
            </div>
            <div v-else class="overflow-x-auto pb-1">
                <InventoryTrendChart :series="productChartSeries" :stockout-series="productStockoutSeries" :pending-dates="productPendingDates" />
            </div>
        </BaseCard>

        <BaseCard v-if="skuCards.length > 0 && !trendLoading && !trendError" title="SKU別サマリー" :description="summaryPeriodLabel" :padded="false">
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
                        <tr v-for="summary in skuCards" :key="summary.skuCode" :data-summary-sku="summary.skuCode" class="border-t border-line-2 first:border-t-0">
                            <td class="py-[5px] pr-3 pl-[18px] text-left font-mono text-xs font-medium text-ink uppercase">
                                <span class="block truncate" :title="summary.skuCode">{{ summary.skuCode }}</span>
                            </td>
                            <td data-summary-field="trend" class="px-3 py-[5px]"><InventoryTrendMiniChart :label="summary.skuCode" :points="summary.points" variant="spark" /></td>
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
                                <span v-if="summary.stockoutDays > 0" class="inline-block min-w-7 rounded-chip bg-signal px-[7px] text-center font-num text-base leading-[1.6] font-bold text-ink">{{
                                    summary.stockoutDays
                                }}</span>
                                <span v-else class="font-num text-[21px] leading-[1.15] font-semibold">0</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </BaseCard>
    </div>
</template>
