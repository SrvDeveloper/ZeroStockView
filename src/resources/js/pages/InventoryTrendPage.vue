<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { fetchInventoryTrend, fetchInventoryTrendProducts, type InventoryScope, type InventoryTrendProduct, type InventoryTrendSeries } from "@/api/inventoryTrends";
import InventoryTrendChart from "@/components/inventory/InventoryTrendChart.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseModal from "@/components/ui/BaseModal.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import type { SelectOption } from "@/types";

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

interface SkuSummary {
    skuCode: string;
    color: string;
    latest: number | null;
    average: number | null;
    minimum: number | null;
    maximum: number | null;
    stockoutDays: number;
}

const SERIES_COLORS = ["#2563eb", "#7c3aed", "#059669", "#ea580c", "#db2777", "#0891b2", "#65a30d", "#9333ea"];
const products = ref<InventoryTrendProduct[]>([]);
const availableBrands = ref<string[]>([]);
const availableRange = ref<{ from: string; to: string } | null>(null);
const productCode = ref("");
const inventoryScope = ref<InventoryScope>("mallTotal");
const trendView = ref<TrendView>("sku");
const chartMode = ref<"line" | "bar">("line");
const activePreset = ref<14 | 30 | 90 | "custom">(30);
const targetStart = ref("");
const targetEnd = ref("");
const rawSeries = ref<InventoryTrendSeries[]>([]);
const selectedSkuCode = ref("");
const productPickerOpen = ref(false);
const pendingProductCode = ref("");
const productBrandFilter = ref<string | null>(null);
const productQuery = ref("");
const productsLoading = ref(false);
const trendLoading = ref(false);
const productsError = ref("");
const trendError = ref("");
let trendRequestId = 0;

const selectedProduct = computed(() => products.value.find((product) => product.productCode === productCode.value));
const brandOptions = computed<SelectOption[]>(() => availableBrands.value.map((brand) => ({ value: brand, label: brand })));
const filteredProductOptions = computed(() => {
    const query = productQuery.value.trim().toLowerCase();
    return products.value
        .filter((product) => !productBrandFilter.value || product.brand === productBrandFilter.value)
        .filter((product) => !query || product.productCode.toLowerCase().includes(query) || product.brand.toLowerCase().includes(query) || product.category.toLowerCase().includes(query));
});
const periodOptions = [
    { value: 14 as const, label: "14日" },
    { value: 30 as const, label: "30日" },
    { value: 90 as const, label: "90日" },
    { value: "custom" as const, label: "カスタム" },
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
const selectedDates = computed(() => {
    if (!targetStart.value || !targetEnd.value) return [];
    const dates: string[] = [];
    for (let date = targetStart.value; date <= targetEnd.value; date = addDays(date, 1)) dates.push(date);
    return dates;
});
const skuChartSeries = computed<TrendSeries[]>(() => {
    const index = rawSeries.value.findIndex((series) => series.skuCode === selectedSkuCode.value);
    const series = rawSeries.value[index];
    if (!series) return [];

    return [
        {
            skuCode: series.skuCode,
            label: series.skuCode,
            color: SERIES_COLORS[index % SERIES_COLORS.length]!,
            points: series.points,
        },
    ];
});
const productChartSeries = computed<TrendSeries[]>(() => {
    if (rawSeries.value.length === 0) return [];

    return [
        {
            skuCode: productCode.value,
            label: "総在庫数",
            color: SERIES_COLORS[0]!,
            points: selectedDates.value.map((date) => {
                const quantities = rawSeries.value.flatMap((series) => {
                    const quantity = series.points.find((point) => point.date === date)?.quantity;
                    return quantity === null || quantity === undefined ? [] : [quantity];
                });
                return { date, quantity: quantities.length === 0 ? null : quantities.reduce((total, quantity) => total + quantity, 0) };
            }),
        },
    ];
});
const productStockoutSeries = computed<StockoutSeries | null>(() => {
    if (trendView.value !== "product" || rawSeries.value.length === 0) return null;

    const stockoutDaysBySku = new Map(rawSeries.value.map((series) => [series.skuCode, series.points.filter((point) => point.quantity === 0).length]));
    const periodStockoutDays = [...stockoutDaysBySku.values()].reduce((total, days) => total + days, 0);

    return {
        label: "欠品SKU数",
        color: "#e11d48",
        points: selectedDates.value.map((date) => {
            const availableSeries = rawSeries.value.flatMap((series) => {
                const quantity = series.points.find((point) => point.date === date)?.quantity;
                return quantity === null || quantity === undefined ? [] : [{ series, quantity }];
            });
            const stockoutSkus = availableSeries
                .filter(({ quantity }) => quantity === 0)
                .map(({ series }) => ({
                    skuCode: series.skuCode,
                    stockoutDays: stockoutDaysBySku.get(series.skuCode) ?? 0,
                }));

            return {
                date,
                quantity: availableSeries.length === 0 ? null : stockoutSkus.length,
                skus: stockoutSkus,
                periodStockoutDays,
            };
        }),
    };
});
const chartSeries = computed<TrendSeries[]>(() => (trendView.value === "sku" ? skuChartSeries.value : productChartSeries.value));
const chartTitle = computed(() => (trendView.value === "sku" ? "SKU別 在庫数の推移" : "品番別 在庫総数・欠品SKU数の推移"));
const chartDescription = computed(() =>
    trendView.value === "sku"
        ? `${selectedInventoryScopeLabel.value}の在庫数を、選択した1SKUについて1日単位で表示しています。`
        : `${selectedInventoryScopeLabel.value}の総在庫数と欠品SKU数を、品番単位で表示しています。`,
);
const skuSummaries = computed<SkuSummary[]>(() =>
    rawSeries.value.map((series, index) => {
        const quantities = series.points.flatMap((point) => (point.quantity === null ? [] : [point.quantity]));
        return {
            skuCode: series.skuCode,
            color: SERIES_COLORS[index % SERIES_COLORS.length]!,
            latest: quantities.at(-1) ?? null,
            average: quantities.length > 0 ? quantities.reduce((sum, quantity) => sum + quantity, 0) / quantities.length : null,
            minimum: quantities.length > 0 ? Math.min(...quantities) : null,
            maximum: quantities.length > 0 ? Math.max(...quantities) : null,
            stockoutDays: quantities.filter((quantity) => quantity === 0).length,
        };
    }),
);
onMounted(loadProducts);

async function loadProducts() {
    productsLoading.value = true;
    productsError.value = "";
    try {
        const response = await fetchInventoryTrendProducts();
        products.value = response.data;
        availableBrands.value = response.brands;
        availableRange.value = response.dateRange;

        if (response.dateRange) {
            targetEnd.value = response.dateRange.to;
            const candidateStart = addDays(response.dateRange.to, -29);
            targetStart.value = candidateStart < response.dateRange.from ? response.dateRange.from : candidateStart;
            activePreset.value = selectedDates.value.length === 30 ? 30 : "custom";
        }

        if (products.value.length > 0) {
            productCode.value = products.value[0]!.productCode;
            pendingProductCode.value = productCode.value;
            await loadTrend(true);
        }
    } catch {
        productsError.value = "対象品番の読み込みに失敗しました。時間をおいて再度お試しください。";
    } finally {
        productsLoading.value = false;
    }
}

async function loadTrend(resetSelection = false) {
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
        const availableCodes = response.series.map((series) => series.skuCode);
        if (resetSelection || !availableCodes.includes(selectedSkuCode.value)) selectedSkuCode.value = availableCodes[0] ?? "";
    } catch {
        if (requestId !== trendRequestId) return;
        rawSeries.value = [];
        selectedSkuCode.value = "";
        trendError.value = "在庫推移の読み込みに失敗しました。時間をおいて再度お試しください。";
    } finally {
        if (requestId === trendRequestId) trendLoading.value = false;
    }
}

function openProductPicker() {
    pendingProductCode.value = productCode.value;
    productBrandFilter.value = null;
    productQuery.value = "";
    productPickerOpen.value = true;
}

function closeProductPicker() {
    productPickerOpen.value = false;
}

function applyProductSelection() {
    const changed = productCode.value !== pendingProductCode.value;
    productCode.value = pendingProductCode.value;
    productPickerOpen.value = false;
    if (changed) void loadTrend(true);
}

function addDays(date: string, amount: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + amount);
    return value.toISOString().slice(0, 10);
}

function formatTargetDate(date: string): string {
    return date.replaceAll("-", "/");
}

function formatSummaryQuantity(quantity: number | null): string {
    return quantity === null ? "—" : `${quantity}点`;
}

function formatSummaryAverage(quantity: number | null): string {
    if (quantity === null) return "—";
    return `${Number(quantity.toFixed(1))}点`;
}

function selectPreset(days: 14 | 30 | 90) {
    if (!targetEnd.value) return;
    activePreset.value = days;
    const start = addDays(targetEnd.value, -(days - 1));
    targetStart.value = availableRange.value && start < availableRange.value.from ? availableRange.value.from : start;
    void loadTrend();
}

function updatePeriodPreset(value: string | number | null) {
    const days = Number(value);
    if (days === 14 || days === 30 || days === 90) selectPreset(days);
    else if (value === "custom") activePreset.value = "custom";
}

function updateInventoryScope(value: string | number | null) {
    if (value !== "mallTotal" && value !== "amazon" && value !== "boss" && value !== "free" && value !== "stock" && value !== "grandTotal") return;
    inventoryScope.value = value;
    void loadTrend();
}

function updateTargetStart(event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.value) {
        input.value = targetStart.value;
        return;
    }
    targetStart.value = input.value;
    if (targetStart.value > targetEnd.value) targetEnd.value = targetStart.value;
    activePreset.value = "custom";
    void loadTrend();
}

function updateTargetEnd(event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.value) {
        input.value = targetEnd.value;
        return;
    }
    targetEnd.value = input.value;
    if (targetEnd.value < targetStart.value) targetStart.value = targetEnd.value;
    activePreset.value = "custom";
    void loadTrend();
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <h1 class="text-xl font-semibold text-slate-900">在庫推移</h1>

        <BaseAlert v-if="productsError" tone="danger" title="対象品番を読み込めませんでした">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ productsError }}</span>
                <BaseButton size="sm" variant="secondary" icon="refresh" @click="() => loadProducts()">再試行</BaseButton>
            </div>
        </BaseAlert>

        <BaseCard :padded="false">
            <div class="flex flex-col gap-3 p-4 lg:flex-row lg:items-end lg:gap-3">
                <div class="min-w-0 flex-1">
                    <span class="mb-1 block text-xs font-medium text-slate-600">対象品番</span>
                    <button
                        type="button"
                        data-testid="product-picker-button"
                        class="flex h-10 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 text-left text-sm transition-colors hover:border-primary-400 hover:bg-primary-50/30 focus-visible:outline-2 focus-visible:outline-primary-500/40"
                        aria-haspopup="dialog"
                        :disabled="productsLoading || !selectedProduct"
                        @click="openProductPicker"
                    >
                        <span v-if="selectedProduct" class="min-w-0 truncate">
                            <span class="font-medium text-slate-900">{{ selectedProduct.productCode }}</span>
                            <span class="ml-3 text-slate-500">{{ selectedProduct.brand }} ／ {{ selectedProduct.category }}</span>
                        </span>
                        <span v-else class="text-slate-400">{{ productsLoading ? "読み込み中..." : "対象品番がありません" }}</span>
                        <span class="flex shrink-0 items-center gap-1.5 text-xs font-medium text-primary-700"><AppIcon name="manage_search" :size="18" />選択</span>
                    </button>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:gap-3">
                    <div class="shrink-0 w-40">
                        <BaseSelect
                            :model-value="inventoryScope"
                            data-testid="inventory-scope-select"
                            :options="inventoryScopeOptions"
                            label="在庫区分"
                            :show-placeholder="false"
                            @update:model-value="updateInventoryScope"
                        />
                    </div>
                    <div class="shrink-0 w-40">
                        <BaseSelect
                            :model-value="activePreset"
                            data-testid="period-select"
                            :options="periodOptions"
                            label="表示期間"
                            :show-placeholder="false"
                            @update:model-value="updatePeriodPreset"
                        />
                    </div>
                    <div>
                        <span class="mb-1 block text-xs font-medium text-slate-600">対象期間</span>
                        <div class="flex items-center gap-2">
                            <input
                                type="date"
                                :value="targetStart"
                                :min="availableRange?.from"
                                :max="availableRange?.to"
                                :disabled="!availableRange"
                                aria-label="対象期間の開始日"
                                class="h-10 rounded-lg border border-slate-300 bg-white px-2.5 text-sm text-slate-700 focus:border-primary-500 focus:outline-2 focus:outline-primary-500/40"
                                @change="updateTargetStart"
                            />
                            <span class="text-sm text-slate-400">〜</span>
                            <input
                                type="date"
                                :value="targetEnd"
                                :min="availableRange?.from"
                                :max="availableRange?.to"
                                :disabled="!availableRange"
                                aria-label="対象期間の終了日"
                                class="h-10 rounded-lg border border-slate-300 bg-white px-2.5 text-sm text-slate-700 focus:border-primary-500 focus:outline-2 focus:outline-primary-500/40"
                                @change="updateTargetEnd"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </BaseCard>

        <BaseModal :open="productPickerOpen" title="対象品番を選択" description="ブランドやキーワードで対象品番を絞り込めます。" width="lg" @close="closeProductPicker">
            <div class="flex flex-col gap-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[200px_minmax(0,1fr)]">
                    <BaseSelect v-model="productBrandFilter" data-testid="product-brand-filter" :options="brandOptions" label="ブランド" placeholder="すべてのブランド" />
                    <BaseInput v-model="productQuery" label="検索" placeholder="品番・ブランド・カテゴリを入力" clearable clear-label="品番検索をクリア" />
                </div>

                <div class="flex items-center justify-between border-b border-slate-100 pb-2 text-xs text-slate-500">
                    <span>候補一覧</span>
                    <span>{{ filteredProductOptions.length }}件</span>
                </div>

                <div v-if="filteredProductOptions.length > 0" class="max-h-72 space-y-2 overflow-y-auto pr-1">
                    <button
                        v-for="product in filteredProductOptions"
                        :key="product.productCode"
                        type="button"
                        :data-product-code="product.productCode"
                        class="flex w-full items-center justify-between gap-4 rounded-lg border px-4 py-3 text-left transition-colors"
                        :class="
                            pendingProductCode === product.productCode
                                ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500/20'
                                : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                        "
                        :aria-pressed="pendingProductCode === product.productCode"
                        @click="pendingProductCode = product.productCode"
                    >
                        <span class="min-w-0">
                            <span class="block font-medium text-slate-900">{{ product.productCode }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ product.brand }} ／ {{ product.category }} ／ {{ product.skuCount }} SKU</span>
                        </span>
                        <AppIcon v-if="pendingProductCode === product.productCode" name="check_circle" :size="22" filled class="shrink-0 text-primary-600" />
                    </button>
                </div>
                <div v-else class="rounded-lg border border-dashed border-slate-300 py-10 text-center">
                    <AppIcon name="search_off" :size="28" class="mx-auto text-slate-300" />
                    <p class="mt-2 text-sm text-slate-500">条件に一致する品番がありません</p>
                </div>
            </div>

            <template #footer>
                <BaseButton variant="secondary" @click="closeProductPicker">キャンセル</BaseButton>
                <BaseButton data-testid="apply-product-selection" variant="primary" :disabled="!pendingProductCode" @click="applyProductSelection">この品番を表示</BaseButton>
            </template>
        </BaseModal>

        <BaseCard :title="chartTitle" :description="chartDescription">
            <template #actions>
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5" role="group" aria-label="在庫推移の確認単位">
                    <button
                        type="button"
                        data-testid="trend-view-sku"
                        class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                        :class="trendView === 'sku' ? 'bg-white text-primary-700 shadow-xs' : 'text-slate-500 hover:text-slate-700'"
                        :aria-pressed="trendView === 'sku'"
                        @click="trendView = 'sku'"
                    >
                        SKU
                    </button>
                    <button
                        type="button"
                        data-testid="trend-view-product"
                        class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                        :class="trendView === 'product' ? 'bg-white text-primary-700 shadow-xs' : 'text-slate-500 hover:text-slate-700'"
                        :aria-pressed="trendView === 'product'"
                        @click="trendView = 'product'"
                    >
                        品番
                    </button>
                </div>
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5" role="group" aria-label="グラフ表示形式">
                    <button
                        type="button"
                        data-testid="chart-mode-line"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors"
                        :class="chartMode === 'line' ? 'bg-white text-primary-700 shadow-xs' : 'text-slate-500 hover:text-slate-700'"
                        :aria-pressed="chartMode === 'line'"
                        @click="chartMode = 'line'"
                    >
                        <AppIcon name="show_chart" :size="15" />折れ線
                    </button>
                    <button
                        type="button"
                        data-testid="chart-mode-bar"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors"
                        :class="chartMode === 'bar' ? 'bg-white text-primary-700 shadow-xs' : 'text-slate-500 hover:text-slate-700'"
                        :aria-pressed="chartMode === 'bar'"
                        @click="chartMode = 'bar'"
                    >
                        <AppIcon name="bar_chart" :size="15" />棒
                    </button>
                </div>
                <span v-if="targetStart && targetEnd" class="inline-flex items-center gap-1.5 text-xs text-slate-500">
                    <AppIcon name="calendar_today" :size="14" />{{ formatTargetDate(targetStart) }}〜{{ formatTargetDate(targetEnd) }}（{{ selectedDates.length }}日間）
                </span>
            </template>

            <div v-if="rawSeries.length > 0 && trendView === 'sku'" class="mb-5 flex flex-wrap items-center gap-2 border-b border-slate-100 pb-4">
                <span class="mr-1 text-xs font-medium text-slate-500">表示SKU</span>
                <button
                    v-for="(sku, index) in rawSeries"
                    :key="sku.skuCode"
                    type="button"
                    :data-sku-code="sku.skuCode"
                    class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="selectedSkuCode === sku.skuCode ? 'border-primary-300 bg-primary-50 text-primary-800 shadow-xs' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'"
                    :aria-pressed="selectedSkuCode === sku.skuCode"
                    @click="selectedSkuCode = sku.skuCode"
                >
                    <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: selectedSkuCode === sku.skuCode ? SERIES_COLORS[index % SERIES_COLORS.length] : '#cbd5e1' }"></span>
                    {{ sku.skuCode }}
                </button>
            </div>
            <div v-else-if="rawSeries.length > 0" data-testid="product-chart-legend" class="mb-5 flex flex-wrap items-center gap-5 border-b border-slate-100 pb-4 text-xs font-medium text-slate-600">
                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-primary-600"></span>総在庫数</span>
                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-rose-600"></span>欠品SKU数</span>
            </div>

            <div v-if="trendLoading" class="flex min-h-80 items-center justify-center gap-2 text-sm text-slate-500" role="status">
                <AppIcon name="progress_activity" :size="22" class="animate-spin text-primary-500" />
                在庫推移を読み込んでいます...
            </div>
            <BaseAlert v-else-if="trendError" tone="danger" title="在庫推移を読み込めませんでした">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span>{{ trendError }}</span>
                    <BaseButton size="sm" variant="secondary" icon="refresh" @click="() => loadTrend()">再試行</BaseButton>
                </div>
            </BaseAlert>
            <BaseEmpty v-else-if="chartSeries.length === 0" icon="show_chart" title="対象期間の在庫データがありません" description="対象品番または対象期間を変更してください。" />
            <div v-else class="overflow-x-auto pb-1">
                <InventoryTrendChart :series="chartSeries" :display-mode="chartMode" :stockout-series="productStockoutSeries" />
            </div>
        </BaseCard>

        <BaseCard
            v-if="skuSummaries.length > 0 && !trendLoading && !trendError"
            title="SKU別サマリー"
            :description="`選択期間の${selectedInventoryScopeLabel}在庫を集計しています。データがない日は平均・欠品日数の対象外です。`"
            :padded="false"
        >
            <div class="overflow-x-auto">
                <table data-testid="sku-summary-table" class="w-full min-w-180 text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-medium">SKU</th>
                            <th class="px-4 py-3 text-right font-medium">最新在庫</th>
                            <th class="px-4 py-3 text-right font-medium">期間平均</th>
                            <th class="px-4 py-3 text-right font-medium">期間最小</th>
                            <th class="px-4 py-3 text-right font-medium">期間最大</th>
                            <th class="px-5 py-3 text-right font-medium">欠品日数</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="summary in skuSummaries" :key="summary.skuCode" :data-summary-sku="summary.skuCode" class="hover:bg-slate-50/70">
                            <td class="px-5 py-3 font-medium text-slate-800">
                                <span class="inline-flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: summary.color }"></span>
                                    {{ summary.skuCode }}
                                </span>
                            </td>
                            <td data-summary-field="latest" class="px-4 py-3 text-right font-medium tabular-nums text-slate-800">{{ formatSummaryQuantity(summary.latest) }}</td>
                            <td data-summary-field="average" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ formatSummaryAverage(summary.average) }}</td>
                            <td data-summary-field="minimum" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ formatSummaryQuantity(summary.minimum) }}</td>
                            <td data-summary-field="maximum" class="px-4 py-3 text-right tabular-nums text-slate-600">{{ formatSummaryQuantity(summary.maximum) }}</td>
                            <td data-summary-field="stockout-days" class="px-5 py-3 text-right tabular-nums">
                                <span :class="summary.stockoutDays > 0 ? 'font-semibold text-rose-600' : 'text-slate-500'">{{ summary.stockoutDays }}日</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </BaseCard>
    </div>
</template>
