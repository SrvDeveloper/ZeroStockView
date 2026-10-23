<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter, type LocationQuery, type LocationQueryRaw } from "vue-router";
import {
    exportInventoryTrend,
    fetchInventoryTrendOverview,
    type InventoryScope,
    type InventoryTrendOverview,
    type InventoryTrendOverviewFocus,
    type InventoryTrendOverviewProduct,
    type InventoryTrendOverviewSort,
} from "@/api/inventoryTrends";
import InventoryTrendMiniChart from "@/components/inventory/InventoryTrendMiniChart.vue";
import SkuStateLegend from "@/components/inventory/SkuStateLegend.vue";
import SkuStateStrip from "@/components/inventory/SkuStateStrip.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BasePagination from "@/components/ui/BasePagination.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import SegmentedControl from "@/components/ui/SegmentedControl.vue";
import StatCard from "@/components/ui/StatCard.vue";
import StatusLamp, { type LampState } from "@/components/ui/StatusLamp.vue";
import { useInventoryTrendListStore } from "@/stores/inventoryTrendList";
import type { SelectOption } from "@/types";
import { formatNumber } from "@/utils/format";
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

type Focus = "all" | InventoryTrendOverviewFocus;

const route = useRoute();
const router = useRouter();
const listStore = useInventoryTrendListStore();

const FOCUS_OPTIONS: { value: Focus; label: string }[] = [
    { value: "all", label: "すべて" },
    { value: "stockout", label: "欠品SKUあり" },
    { value: "increased", label: "欠品が増えた" },
    { value: "decreasing", label: "在庫が10%以上減った" },
    { value: "long", label: "30日以上の欠品" },
];
const SORTS: readonly InventoryTrendOverviewSort[] = ["stockout", "delta", "latest", "code"];
/** 「在庫が10%以上減った」と同じ境目で、前週比を強調する */
const DECREASE_RATE = -0.1;
const SEARCH_DELAY_MS = 300;
const WEEKDAYS = ["日", "月", "火", "水", "木", "金", "土"];

function single(value: LocationQuery[string] | undefined): string {
    return typeof value === "string" ? value : "";
}

function todayInTokyo(): string {
    return new Intl.DateTimeFormat("sv-SE", { timeZone: "Asia/Tokyo" }).format(new Date());
}

const initial = parseInventoryTrendQuery(route.query);
const scope = ref<InventoryScope>(initial.scope);
const period = ref<PeriodPreset>(initial.period);
const customFrom = ref(initial.from ?? "");
const customTo = ref(initial.to ?? "");
const group = ref<string | null>(single(route.query.group) || null);
const brand = ref<string | null>(single(route.query.brand) || null);
const searchInput = ref(single(route.query.search));
const search = ref(searchInput.value);
const focus = ref<Focus>(FOCUS_OPTIONS.some((option) => option.value === route.query.focus) ? (route.query.focus as Focus) : "all");
const sort = ref<InventoryTrendOverviewSort>(SORTS.includes(route.query.sort as InventoryTrendOverviewSort) ? (route.query.sort as InventoryTrendOverviewSort) : "stockout");
const page = ref(Math.max(1, Number.parseInt(single(route.query.page), 10) || 1));

const overview = ref<InventoryTrendOverview | null>(null);
const loading = ref(true);
const error = ref("");
const exportMenuOpen = ref(false);
const exportMenuRef = ref<HTMLElement | null>(null);
const exportLoading = ref(false);
const exportError = ref("");
/** 期間指定でないときの期間の終わり（最新の調査日）。最初は今日として読み、応答の dateRange で直す */
const latestDate = ref(todayInTokyo());
let requestId = 0;
let searchTimer: number | undefined;

const range = computed(() => {
    if (period.value === "custom" && customFrom.value && customTo.value) return { from: customFrom.value, to: customTo.value };
    const days = period.value === "custom" ? 30 : Number(period.value);
    return { from: addDays(latestDate.value, -(days - 1)), to: latestDate.value };
});
const trendQuery = computed<InventoryTrendQuery>(() => ({
    scope: scope.value,
    period: period.value,
    from: period.value === "custom" ? range.value.from : null,
    to: period.value === "custom" ? range.value.to : null,
}));
const scopeLabel = computed(() => INVENTORY_SCOPE_OPTIONS.find((option) => option.value === scope.value)?.label ?? "");
const groupOptions = computed<SelectOption[]>(() => (overview.value?.groups ?? []).map((item) => ({ value: item.key, label: item.name })));
const brandOptions = computed<SelectOption[]>(() => (overview.value?.brands ?? []).map((item) => ({ value: item, label: item })));
const summary = computed(() => overview.value?.summary ?? null);
const rows = computed(() => overview.value?.data ?? []);
const totalPages = computed(() => (overview.value ? Math.max(1, Math.ceil(overview.value.meta.total / overview.value.meta.perPage)) : 1));
const hasFilters = computed(() => Boolean(group.value || brand.value || search.value || focus.value !== "all"));
const latestSurvey = computed(() => overview.value?.latestSurvey ?? null);
const latestSurveyLamp = computed<{ state: LampState; label: string; detail: string } | null>(() => {
    const survey = latestSurvey.value;
    if (!survey) return null;
    const label = `最新の調査 ${formatMonthDay(survey.date)}`;
    if (survey.amazonInventoryStatus === "complete") return { state: "ok", label, detail: survey.amazonBasisAt ? `Amazon 取込済み ${formatTime(survey.amazonBasisAt)}` : "Amazon 取込済み" };
    if (survey.amazonInventoryStatus === "partial") return { state: "warn", label, detail: "Amazon 一部未取得" };
    if (survey.amazonInventoryStatus === "pending") return { state: "warn", label, detail: "Amazon 取込待ち" };
    return { state: "ok", label, detail: "" };
});
const stockoutDifference = computed(() => {
    const value = summary.value;
    if (!value || value.stockoutSkuCountWeekAgo === null) return null;
    return value.stockoutSkuCount - value.stockoutSkuCountWeekAgo;
});
const totalDelta = computed(() => {
    const value = summary.value;
    return value ? rate(value.totalQuantity, value.totalQuantityWeekAgo) : null;
});

/** 一覧の条件を URL のクエリにする。初期値は書かない */
function listQuery(): LocationQueryRaw {
    const query: LocationQueryRaw = replaceInventoryTrendQuery({}, trendQuery.value);
    if (group.value) query.group = group.value;
    if (brand.value) query.brand = brand.value;
    if (search.value) query.search = search.value;
    if (focus.value !== "all") query.focus = focus.value;
    if (sort.value !== "stockout") query.sort = sort.value;
    if (page.value > 1) query.page = String(page.value);
    return query;
}

/** adjusted: 最新の調査日に合わせて読み直したあとか（読み直しは1回だけにする） */
async function load(adjusted = false) {
    const id = ++requestId;
    loading.value = true;
    error.value = "";
    try {
        const response = await fetchInventoryTrendOverview({
            ...range.value,
            scope: scope.value,
            group: group.value,
            brand: brand.value,
            search: search.value,
            focus: focus.value === "all" ? null : focus.value,
            sort: sort.value === "stockout" ? null : sort.value,
            page: page.value,
        });
        if (id !== requestId) return;
        // 期間指定でないときは、最新の調査日までの期間で読み直す
        if (!adjusted && period.value !== "custom" && response.dateRange && response.dateRange.to !== latestDate.value) {
            latestDate.value = response.dateRange.to;
            void load(true);
            return;
        }
        overview.value = response;
        listStore.remember(
            response.data.map((product) => product.productCode),
            listQuery(),
        );
    } catch {
        if (id !== requestId) return;
        overview.value = null;
        error.value = "在庫推移の一覧を読み込めませんでした。時間をおいて再度お試しください。";
    } finally {
        if (id === requestId) loading.value = false;
    }
}

/** 条件を変えたら、ページを先頭に戻し、URL に残して読み直す */
function applyChange(change: () => void, resetPage = true) {
    change();
    if (resetPage) page.value = 1;
    void router.replace({ query: listQuery() });
    void load();
}

function updateScope(value: InventoryScope) {
    applyChange(() => (scope.value = value));
}

function updatePeriod(value: PeriodPreset) {
    applyChange(() => {
        if (value === "custom") {
            customFrom.value = range.value.from;
            customTo.value = range.value.to;
        }
        period.value = value;
    });
}

function updateCustomDate(edge: "from" | "to", event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.value) {
        input.value = edge === "from" ? customFrom.value : customTo.value;
        return;
    }
    applyChange(() => {
        if (edge === "from") {
            customFrom.value = input.value;
            if (customTo.value < customFrom.value) customTo.value = customFrom.value;
            const maximumTo = addDays(customFrom.value, MAXIMUM_PERIOD_DAYS - 1);
            if (customTo.value > maximumTo) customTo.value = maximumTo;
        } else {
            customTo.value = input.value;
            if (customFrom.value > customTo.value) customFrom.value = customTo.value;
            const minimumFrom = addDays(customTo.value, -(MAXIMUM_PERIOD_DAYS - 1));
            if (customFrom.value < minimumFrom) customFrom.value = minimumFrom;
        }
    });
}

function updateGroup(value: string | number | null) {
    applyChange(() => (group.value = value === null ? null : String(value)));
}

function updateBrand(value: string | number | null) {
    applyChange(() => (brand.value = value === null ? null : String(value)));
}

function selectFocus(value: Focus) {
    if (focus.value === value) return;
    applyChange(() => (focus.value = value));
}

function selectSort(value: InventoryTrendOverviewSort) {
    if (sort.value === value) return;
    applyChange(() => (sort.value = value));
}

function changePage(value: number) {
    applyChange(() => (page.value = value), false);
}

watch(searchInput, (value) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        if (value.trim() === search.value) return;
        applyChange(() => (search.value = value.trim()));
    }, SEARCH_DELAY_MS);
});

function ariaSort(value: InventoryTrendOverviewSort): "ascending" | "descending" | "none" {
    if (sort.value !== value) return "none";
    return value === "stockout" ? "descending" : "ascending";
}

function detailLink(product: InventoryTrendOverviewProduct) {
    return { name: "inventory-trend", params: { productCode: product.productCode }, query: listQuery() };
}

function rate(latest: number | null, weekAgo: number | null): number | null {
    return latest === null || weekAgo === null || weekAgo === 0 ? null : (latest - weekAgo) / weekAgo;
}

function productDelta(product: InventoryTrendOverviewProduct): number | null {
    return rate(product.latest.quantity, product.weekAgo?.quantity ?? null);
}

/** 例: ▲ 4.2%、▼ 28.1%、±0.0%。比べられないときは — */
function formatRate(value: number | null): string {
    if (value === null) return "—";
    const percent = Math.abs(value * 100).toFixed(1);
    if (percent === "0.0") return "±0.0%";
    return `${value > 0 ? "▲" : "▼"} ${percent}%`;
}

function formatQuantity(value: number | null): string {
    return value === null ? "—" : formatNumber(value);
}

function stockoutIncrease(product: InventoryTrendOverviewProduct): number {
    return product.stockoutSkuCountWeekAgo === null ? 0 : product.stockoutSkuCount - product.stockoutSkuCountWeekAgo;
}

function formatMonthDay(date: string): string {
    const [year, month, day] = date.split("-").map(Number);
    const weekday = WEEKDAYS[new Date(Date.UTC(year!, month! - 1, day!)).getUTCDay()];
    return `${month}/${day}（${weekday}）`;
}

function formatTime(iso: string): string {
    return new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", hour: "2-digit", minute: "2-digit" }).format(new Date(iso));
}

async function exportAll() {
    exportMenuOpen.value = false;
    exportLoading.value = true;
    exportError.value = "";
    try {
        const blob = await exportInventoryTrend({ ...range.value });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = `全品番_${range.value.from}_${range.value.to}_日次在庫.csv`;
        link.click();
        URL.revokeObjectURL(url);
    } catch {
        exportError.value = "日次在庫のCSV出力に失敗しました。時間をおいて再度お試しください。";
    } finally {
        exportLoading.value = false;
    }
}

function closeExportMenuOnOutsideClick(event: MouseEvent) {
    if (exportMenuOpen.value && exportMenuRef.value && !exportMenuRef.value.contains(event.target as Node)) exportMenuOpen.value = false;
}

function closeExportMenuOnEscape(event: KeyboardEvent) {
    if (event.key === "Escape") exportMenuOpen.value = false;
}

onMounted(() => {
    document.addEventListener("click", closeExportMenuOnOutsideClick);
    document.addEventListener("keydown", closeExportMenuOnEscape);
    void load();
});

onBeforeUnmount(() => {
    window.clearTimeout(searchTimer);
    document.removeEventListener("click", closeExportMenuOnOutsideClick);
    document.removeEventListener("keydown", closeExportMenuOnEscape);
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0">
                <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">在庫推移</h1>
                <p class="mt-1 text-[12.5px] text-ink-2">全品番の在庫の動きと欠品の状況を一覧で確認し、気になる品番を開いて詳しく見ます。</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 sm:ml-auto">
                <StatusLamp
                    v-if="latestSurveyLamp"
                    data-testid="latest-survey-status"
                    class="text-[11.5px] text-ink-2"
                    :state="latestSurveyLamp.state"
                    :label="latestSurveyLamp.label"
                    :detail="latestSurveyLamp.detail"
                />
                <div ref="exportMenuRef" class="relative">
                    <BaseButton
                        data-testid="inventory-trend-export-button"
                        variant="secondary"
                        icon="download"
                        aria-haspopup="menu"
                        :aria-expanded="exportMenuOpen"
                        :loading="exportLoading"
                        :disabled="exportLoading"
                        @click="exportMenuOpen = !exportMenuOpen"
                    >
                        日次在庫CSV
                    </BaseButton>
                    <div v-if="exportMenuOpen" class="absolute right-0 z-40 mt-1.5 w-72 overflow-hidden rounded-card border border-line bg-surface py-1 shadow-float" role="menu">
                        <button
                            type="button"
                            data-testid="inventory-trend-export-all"
                            role="menuitem"
                            class="block w-full px-3.5 py-2 text-left text-[12.5px] text-ink hover:bg-surface-2"
                            @click="exportAll"
                        >
                            全品番の日次CSV<span class="ml-2 font-mono text-[11px] text-muted">{{ range.from }} → {{ range.to }}</span>
                        </button>
                        <p class="mt-1 border-t border-line-2 px-3.5 pt-2 pb-1.5 text-[11px] leading-relaxed whitespace-normal text-muted">
                            対象期間の調査日の在庫と、調査がない日のAmazon取得結果を出力します。Amazon取得だけの日は他の在庫区分が空欄です。
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <BaseAlert v-if="exportError" tone="danger" title="日次在庫を出力できませんでした">{{ exportError }}</BaseAlert>

        <BaseCard>
            <div class="flex flex-wrap items-end gap-x-5 gap-y-3">
                <div>
                    <span class="mb-1 block text-[11px] font-medium text-ink-2">在庫区分</span>
                    <div class="flex h-8 items-center" data-testid="inventory-scope-select">
                        <SegmentedControl :model-value="scope" :options="INVENTORY_SCOPE_OPTIONS" label="在庫区分" @update:model-value="updateScope" />
                    </div>
                </div>
                <div>
                    <span class="mb-1 block text-[11px] font-medium text-ink-2">期間</span>
                    <div class="flex h-8 items-center" data-testid="period-select">
                        <SegmentedControl :model-value="period" :options="PERIOD_OPTIONS" label="期間" @update:model-value="updatePeriod" />
                    </div>
                </div>
                <div v-if="period === 'custom'" class="min-w-0">
                    <span class="mb-1 block text-[11px] font-medium text-ink-2">対象期間</span>
                    <div
                        class="flex h-8 items-center gap-1.5 rounded-control border border-line-strong bg-surface px-1.5 font-mono text-[11.5px] text-ink shadow-field focus-within:border-ink-2 focus-within:ring-3 focus-within:ring-ink/10"
                    >
                        <input
                            type="date"
                            :value="customFrom"
                            :min="overview?.dateRange?.from"
                            :max="overview?.dateRange?.to"
                            aria-label="対象期間の開始日"
                            class="h-7 min-w-0 bg-transparent px-1 focus:outline-none"
                            @change="updateCustomDate('from', $event)"
                        />
                        <span class="text-faint" aria-hidden="true">→</span>
                        <input
                            type="date"
                            :value="customTo"
                            :min="overview?.dateRange?.from"
                            :max="overview?.dateRange?.to"
                            aria-label="対象期間の終了日"
                            class="h-7 min-w-0 bg-transparent px-1 focus:outline-none"
                            @change="updateCustomDate('to', $event)"
                        />
                    </div>
                </div>
                <div class="w-full sm:w-44">
                    <BaseSelect data-testid="group-filter" :model-value="group" :options="groupOptions" label="品番グループ" placeholder="すべてのグループ" @update:model-value="updateGroup" />
                </div>
                <div class="w-full sm:w-40">
                    <BaseSelect data-testid="brand-filter" :model-value="brand" :options="brandOptions" label="ブランド" placeholder="すべてのブランド" @update:model-value="updateBrand" />
                </div>
                <div class="min-w-[200px] flex-1">
                    <BaseInput v-model="searchInput" data-testid="product-search" label="品番・SKU・ASIN" placeholder="品番・SKU・ASINで検索" clearable clear-label="検索をクリア" />
                </div>
            </div>
        </BaseCard>

        <section v-if="summary" aria-label="全体の状況" class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-3" data-testid="overview-summary">
            <StatCard title="対象品番" :value="formatNumber(summary.productCount)" unit="品番" :note="`${formatNumber(summary.skuCount)} SKU`" />
            <StatCard
                :title="latestSurvey ? `欠品SKU（${formatMonthDay(latestSurvey.date)}時点）` : '欠品SKU'"
                :value="formatNumber(summary.stockoutSkuCount)"
                unit="SKU"
                :note="stockoutDifference === null ? '前週と比べられません' : `前週から ${stockoutDifference >= 0 ? '+' : ''}${stockoutDifference} SKU`"
                :alert="summary.stockoutSkuCount > 0"
            />
            <StatCard
                title="30日以上続いている欠品"
                :value="formatNumber(summary.longStockoutSkuCount)"
                unit="SKU"
                :note="`${formatNumber(summary.longStockoutProductCount)}品番`"
                :alert="summary.longStockoutSkuCount > 0"
            />
            <StatCard
                :title="`在庫の合計（${scopeLabel}）`"
                :value="formatQuantity(summary.totalQuantity)"
                unit="点"
                :note="summary.totalQuantity === null ? '未確定の倉庫があります' : `前週比 ${formatRate(totalDelta)}`"
            />
        </section>

        <BaseCard :padded="false">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2.5 border-b border-line-2 px-4 py-3">
                <h2 class="text-[13.5px] font-semibold">品番一覧</h2>
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="注目する品番">
                    <button
                        v-for="option in FOCUS_OPTIONS"
                        :key="option.value"
                        type="button"
                        :data-focus="option.value"
                        :aria-pressed="focus === option.value"
                        class="inline-flex h-[30px] items-center gap-1.5 rounded-full border px-2.5 text-[12px] transition-colors"
                        :class="focus === option.value ? 'border-ink bg-ink font-semibold text-white' : 'border-line-strong bg-surface text-ink hover:cursor-pointer hover:bg-surface-2'"
                        @click="selectFocus(option.value)"
                    >
                        {{ option.label }}
                        <span class="font-num text-[14px] font-semibold" :class="focus === option.value ? 'text-white' : 'text-ink-2'">{{
                            summary ? formatNumber(summary.focusCounts[option.value]) : "—"
                        }}</span>
                    </button>
                </div>
                <SkuStateLegend class="sm:ml-auto" />
            </div>

            <BaseAlert v-if="error" class="m-4" tone="danger" title="在庫推移の一覧を読み込めませんでした">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span>{{ error }}</span>
                    <BaseButton size="sm" variant="secondary" icon="refresh" @click="() => load()">再試行</BaseButton>
                </div>
            </BaseAlert>
            <div v-else-if="loading && !overview" class="flex min-h-40 items-center justify-center gap-2 text-[12.5px] text-muted" role="status">
                <AppIcon name="progress_activity" :size="22" class="animate-spin text-ink-2" />
                在庫推移の一覧を読み込んでいます...
            </div>
            <BaseEmpty
                v-else-if="rows.length === 0"
                icon="show_chart"
                :title="hasFilters ? '条件に合う品番がありません' : '在庫推移のある品番がありません'"
                :description="hasFilters ? '絞り込みの条件を変えてください。' : '対象期間に調査がありません。期間を変えるか、データ取込で調査を作ってください。'"
            />
            <template v-else>
                <div class="overflow-x-auto" :aria-busy="loading">
                    <table data-testid="overview-table" class="w-full min-w-[1060px] border-collapse text-[12.5px] tabular-nums">
                        <thead>
                            <tr class="bg-surface-2 text-left text-[11px] text-muted">
                                <th scope="col" class="px-4 py-2 font-semibold" :aria-sort="ariaSort('code')">
                                    <button type="button" data-sort="code" class="hover:cursor-pointer hover:text-ink" :class="sort === 'code' ? 'text-ink' : ''" @click="selectSort('code')">
                                        品番<span v-if="sort === 'code'" aria-hidden="true"> ↑</span>
                                    </button>
                                </th>
                                <th scope="col" class="px-2.5 py-2 font-semibold">品番グループ</th>
                                <th scope="col" class="px-2.5 py-2 text-right font-semibold" :aria-sort="ariaSort('latest')">
                                    <button type="button" data-sort="latest" class="hover:cursor-pointer hover:text-ink" :class="sort === 'latest' ? 'text-ink' : ''" @click="selectSort('latest')">
                                        最新在庫<span v-if="sort === 'latest'" aria-hidden="true"> ↑</span>
                                    </button>
                                </th>
                                <th scope="col" class="px-2.5 py-2 text-right font-semibold" :aria-sort="ariaSort('delta')">
                                    <button type="button" data-sort="delta" class="hover:cursor-pointer hover:text-ink" :class="sort === 'delta' ? 'text-ink' : ''" @click="selectSort('delta')">
                                        前週比<span v-if="sort === 'delta'" aria-hidden="true"> ↑</span>
                                    </button>
                                </th>
                                <th scope="col" class="px-2.5 py-2 font-semibold">期間の推移</th>
                                <th scope="col" class="px-2.5 py-2 font-semibold">SKUの状況（最新）</th>
                                <th scope="col" class="px-2.5 py-2 text-right font-semibold" :aria-sort="ariaSort('stockout')">
                                    <button
                                        type="button"
                                        data-sort="stockout"
                                        class="hover:cursor-pointer hover:text-ink"
                                        :class="sort === 'stockout' ? 'text-ink' : ''"
                                        @click="selectSort('stockout')"
                                    >
                                        欠品SKU<span v-if="sort === 'stockout'" aria-hidden="true"> ↓</span>
                                    </button>
                                </th>
                                <th scope="col" class="px-2.5 py-2 text-right font-semibold">期間の欠品<br />（SKU・日）</th>
                                <th scope="col" class="px-4 py-2"><span class="sr-only">開く</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="product in rows" :key="product.productCode" :data-overview-product="product.productCode" class="border-t border-line-2 align-middle">
                                <td class="px-4 py-2.5">
                                    <RouterLink
                                        :to="detailLink(product)"
                                        class="font-mono text-[13px] font-semibold text-ink uppercase underline decoration-line-stronger underline-offset-[3px] hover:decoration-ink"
                                        data-overview-link
                                        >{{ product.productCode }}</RouterLink
                                    >
                                    <div class="mt-0.5 text-[11px] text-muted">{{ product.brand }} · {{ product.category }} · {{ product.skuCount }} SKU</div>
                                </td>
                                <td class="px-2.5 py-2.5">
                                    <!-- 複数のグループに入っている品番は、先頭のグループと残りの数（+2 など）を出す（全グループの名前は title で見られる） -->
                                    <span v-if="product.groups[0]" class="inline-flex max-w-full items-stretch gap-1 align-middle" :title="product.groups.map((item) => item.name).join('、')">
                                        <span
                                            data-overview-group
                                            class="inline-block max-w-[150px] truncate rounded-chip border px-1.5 py-px text-[10.5px] leading-4"
                                            :class="product.groups[0].key === 'ungrouped' ? 'border-dashed border-line-stronger text-muted' : 'border-transparent bg-tint text-ink-2'"
                                            >{{ product.groups[0].name }}</span
                                        >
                                        <span
                                            v-if="product.groups.length > 1"
                                            data-overview-group-more
                                            class="shrink-0 rounded-chip border border-line-strong px-1 py-px font-num text-[10.5px] leading-4 font-semibold text-ink-2"
                                            :aria-label="`ほか${product.groups.length - 1}グループ`"
                                            >+{{ product.groups.length - 1 }}</span
                                        >
                                    </span>
                                </td>
                                <td class="px-2.5 py-2.5 text-right font-num text-[19px] font-semibold" data-overview-field="latest">{{ formatQuantity(product.latest.quantity) }}</td>
                                <td class="px-2.5 py-2.5 text-right whitespace-nowrap" data-overview-field="delta">
                                    <span
                                        :data-strong="productDelta(product) !== null && productDelta(product)! <= DECREASE_RATE ? 'true' : undefined"
                                        :class="
                                            productDelta(product) !== null && productDelta(product)! <= DECREASE_RATE
                                                ? 'inline-block rounded-chip bg-warn-soft px-1.5 py-0.5 font-bold text-ink'
                                                : 'text-ink-2'
                                        "
                                        >{{ formatRate(productDelta(product)) }}</span
                                    >
                                </td>
                                <td class="px-2.5 py-1.5">
                                    <InventoryTrendMiniChart :label="product.productCode" :points="product.points" variant="spark" />
                                </td>
                                <td class="px-2.5 py-2.5"><SkuStateStrip :states="product.skuStates" /></td>
                                <td class="px-2.5 py-2.5 text-right whitespace-nowrap" data-overview-field="stockout">
                                    <span class="font-num text-[19px] font-semibold">{{ product.stockoutSkuCount }}</span
                                    ><span class="text-[11.5px] text-muted"> / {{ product.skuCount }}</span>
                                    <div v-if="stockoutIncrease(product) > 0" class="text-[10.5px] font-semibold text-ink-2">前週から +{{ stockoutIncrease(product) }}</div>
                                </td>
                                <td class="px-2.5 py-2.5 text-right font-num text-[16px] font-semibold" data-overview-field="stockout-days">{{ formatNumber(product.stockoutSkuDays) }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <RouterLink
                                        :to="detailLink(product)"
                                        :aria-label="`${product.productCode}の在庫推移を開く`"
                                        class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-tint"
                                        ><AppIcon name="chevron_right" :size="18"
                                    /></RouterLink>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <BasePagination v-if="overview" :page="overview.meta.page" :total-pages="totalPages" :total="overview.meta.total" :per-page="overview.meta.perPage" @change="changePage" />
            </template>
        </BaseCard>
    </div>
</template>
