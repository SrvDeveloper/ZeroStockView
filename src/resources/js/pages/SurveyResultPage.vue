<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRouter } from "vue-router";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseModal from "@/components/ui/BaseModal.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import MemoField from "@/components/ui/MemoField.vue";
import SegmentedControl from "@/components/ui/SegmentedControl.vue";
import StatCard from "@/components/ui/StatCard.vue";
import StatusLamp, { type LampState } from "@/components/ui/StatusLamp.vue";
import StockCell from "@/components/ui/StockCell.vue";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { IMPORT_FILE_TYPES, type SelectOption, type StockQuantities } from "@/types";
import { formatDateTime } from "@/utils/format";
import { amazonStockState as amazonStockStateOf, amazonTotal, bossTotal, previousSurvey, skuTotal, stockState, summarizeSurvey, type StockState } from "@/utils/stock";

const props = defineProps<{ id: string; initialGroup?: string }>();

const router = useRouter();
const importSettingsStore = useImportSettingsStore();
const surveysStore = useSurveysStore();
const toast = useUiStore();

const survey = computed(() => surveysStore.getSurvey(props.id));
const includesAmazonInventory = computed(() => survey.value?.includesAmazonInventory !== false);
const hasAmazonStock = computed(() => includesAmazonInventory.value || survey.value?.amazonSource === "sp_api");
const amazonStatus = computed(() => survey.value?.amazonInventoryStatus ?? (survey.value?.amazonSource === "sp_api" ? (survey.value.amazonBasisAt ? "complete" : "pending") : "legacy_file"));
const refreshingSurvey = ref(false);
const refreshError = ref("");
let refreshTimer: number | undefined;
watch(
    [() => props.id, () => survey.value?.amazonSource],
    ([, source]) => {
        if (refreshTimer !== undefined) window.clearInterval(refreshTimer);
        refreshTimer = undefined;
        if (source === "sp_api") {
            if (surveysStore.loaded) void refreshSurvey();
            refreshTimer = window.setInterval(() => void refreshSurvey(), 30000);
        }
    },
    { immediate: true },
);

async function refreshSurvey() {
    if (refreshingSurvey.value) return;
    refreshingSurvey.value = true;
    try {
        await surveysStore.refreshSurvey(props.id);
        refreshError.value = "";
    } catch {
        refreshError.value = "調査結果を更新できませんでした。再読み込みしてください。";
    } finally {
        refreshingSurvey.value = false;
    }
}
const isLatest = computed(() => surveysStore.latestSurvey?.id === props.id);
const rerunSettingId = ref<string | null>(null);
const rerunMenuOpen = ref(false);
const rerunRunning = ref(false);
const rerunError = ref("");
const hasReusableFiles = computed(() => {
    const fileTypes = new Set(survey.value?.files.map((file) => file.type) ?? []);
    return IMPORT_FILE_TYPES.some(({ type }) => fileTypes.has(type));
});
const rerunSettingOptions = computed<SelectOption[]>(() => importSettingsStore.settings.map((setting) => ({ value: setting.id, label: `${setting.name}（${setting.productCodes.length}件）` })));
const canRerun = computed(() => hasReusableFiles.value && rerunSettingId.value !== null && !rerunRunning.value);

const selectedGroupKey = ref(props.initialGroup ?? "");
watch(
    () => props.initialGroup,
    (group) => {
        selectedGroupKey.value = group ?? "";
    },
);
const groupOptions = computed<SelectOption[]>(() =>
    (survey.value?.groups ?? (survey.value?.importSettingName ? [{ key: `legacy:${survey.value.importSettingName}`, name: survey.value.importSettingName }] : [])).map((group) => ({
        value: group.key,
        label: group.name,
    })),
);
const groupProducts = computed(() =>
    (survey.value?.products ?? [])
        .filter(
            (product) =>
                !selectedGroupKey.value ||
                product.groups?.some((group) => group.key === selectedGroupKey.value) ||
                (!product.groups && survey.value?.importSettingName && selectedGroupKey.value === `legacy:${survey.value.importSettingName}`),
        )
        .sort((left, right) =>
            selectedGroupKey.value
                ? (left.groups?.find((group) => group.key === selectedGroupKey.value)?.sortOrder ?? Number.MAX_SAFE_INTEGER) -
                  (right.groups?.find((group) => group.key === selectedGroupKey.value)?.sortOrder ?? Number.MAX_SAFE_INTEGER)
                : 0,
        ),
);
const brandOptions = computed<SelectOption[]>(() => Array.from(new Set(groupProducts.value.map((p) => p.brand))).map((b) => ({ value: b, label: b })));
const categoryOptions = computed<SelectOption[]>(() => Array.from(new Set(groupProducts.value.map((p) => p.category))).map((c) => ({ value: c, label: c })));

const brandFilter = ref<string | null>(null);
const categoryFilter = ref<string | null>(null);
const productCodeQuery = ref("");
const skuQuery = ref("");
const asinQuery = ref("");
onBeforeUnmount(() => {
    if (refreshTimer !== undefined) window.clearInterval(refreshTimer);
});

const summary = computed(() => (survey.value ? summarizeSurvey(survey.value) : null));
const previousSummary = computed(() => {
    const previous = survey.value ? previousSurvey(surveysStore.surveys, survey.value) : null;
    return previous ? summarizeSurvey(previous) : null;
});

/** 前回比。直前の調査がなければ空 */
function compareWithPrevious(key: "skuCount" | "allZeroCount" | "partialZeroCount"): string {
    if (!summary.value || !previousSummary.value) return "";
    const diff = summary.value[key] - previousSummary.value[key];
    if (diff === 0) return "前回比 ±0";
    return diff > 0 ? `前回比 +${diff} ▲` : `前回比 −${Math.abs(diff)} ▼`;
}

const amazonLamp = computed<{ state: LampState; label: string }>(() => {
    if (!hasAmazonStock.value) return { state: "warn", label: "取得なし" };
    switch (amazonStatus.value) {
        case "complete":
            return { state: "ok", label: "取込済み" };
        case "partial":
            return { state: "warn", label: "一部未取得" };
        case "pending":
            return { state: "warn", label: "取込待ち" };
        case "legacy_file":
            return { state: "ok", label: "旧ファイル" };
        default:
            return { state: "warn", label: "判定不能" };
    }
});

const amazonMatchNote = computed(() => {
    const skus = survey.value?.products.flatMap((product) => product.skus) ?? [];
    const targets = survey.value?.amazonSource === "sp_api" ? skus.filter((sku) => sku.asin.trim()) : skus;
    const matched = targets.filter((sku) => amazonTotal(sku.stock) !== null).length;
    const basis = survey.value?.amazonBasisAt ? `基準 ${formatDateTime(survey.value.amazonBasisAt).slice(11)} ・ ` : "";
    return `${basis}照合 ${matched}/${targets.length}`;
});

const ZERO_STOCK_BUTTONS = [
    { key: "amazonZero", label: "Amazon" },
    { key: "bossZero", label: "BOSS" },
    { key: "freeZero", label: "フリー" },
    { key: "ecZero", label: "ECストック" },
] as const;
const HAS_STOCK_BUTTONS = [
    { key: "amazonHas", label: "Amazon" },
    { key: "bossHas", label: "BOSS" },
    { key: "freeHas", label: "フリー" },
    { key: "ecHas", label: "ECストック" },
] as const;
const zeroStockButtons = computed(() => (hasAmazonStock.value ? ZERO_STOCK_BUTTONS : ZERO_STOCK_BUTTONS.filter((button) => button.key !== "amazonZero")));
const hasStockButtons = computed(() => (hasAmazonStock.value ? HAS_STOCK_BUTTONS : HAS_STOCK_BUTTONS.filter((button) => button.key !== "amazonHas")));

const activeConditions = ref<string[]>([]);
const conditionOperator = ref<"and" | "or">("and");

function toggleCondition(key: string) {
    const idx = activeConditions.value.indexOf(key);
    if (idx === -1) activeConditions.value.push(key);
    else activeConditions.value.splice(idx, 1);
}

function matchesConditions(stock: StockQuantities): boolean {
    if (activeConditions.value.length === 0) return true;

    const matches = (key: string) => {
        switch (key) {
            case "amazonZero":
                return stock.amazonOwn !== null && stock.amazonFba !== null && stock.amazonOwn === 0 && stock.amazonFba === 0;
            case "bossZero":
                return stock.bossOwn === 0 && stock.bossRfc === 0;
            case "freeZero":
                return stock.freeStock === 0;
            case "ecZero":
                return stock.ecStock === 0;
            case "amazonHas":
                return (stock.amazonOwn ?? 0) + (stock.amazonFba ?? 0) > 0;
            case "bossHas":
                return (stock.bossOwn ?? 0) + (stock.bossRfc ?? 0) > 0;
            case "freeHas":
                return stock.freeStock !== null && stock.freeStock > 0;
            case "ecHas":
                return stock.ecStock !== null && stock.ecStock > 0;
            default:
                return true;
        }
    };

    return conditionOperator.value === "and" ? activeConditions.value.every(matches) : activeConditions.value.some(matches);
}

const hasActiveFilters = computed(
    () => Boolean(selectedGroupKey.value || brandFilter.value || categoryFilter.value || productCodeQuery.value || skuQuery.value || asinQuery.value) || activeConditions.value.length > 0,
);

function clearFilters() {
    selectedGroupKey.value = "";
    brandFilter.value = null;
    categoryFilter.value = null;
    productCodeQuery.value = "";
    skuQuery.value = "";
    asinQuery.value = "";
    activeConditions.value = [];
    conditionOperator.value = "and";
}

const filteredProducts = computed(() => {
    const s = survey.value;
    if (!s) return [];
    const productQ = productCodeQuery.value.trim().toLowerCase();
    const skuQ = skuQuery.value.trim().toLowerCase();
    const asinQ = asinQuery.value.trim().toLowerCase();
    const hasTextQuery = Boolean(skuQ || asinQ);

    return groupProducts.value
        .filter((p) => !brandFilter.value || p.brand === brandFilter.value)
        .filter((p) => !categoryFilter.value || p.category === categoryFilter.value)
        .filter((p) => !productQ || p.productCode.toLowerCase().includes(productQ))
        .map((p) => ({
            ...p,
            skus: p.skus
                .filter((sku) => matchesConditions(sku.stock))
                .map((sku) => ({
                    ...sku,
                    skuMatched: hasTextQuery && (!skuQ || sku.skuCode.toLowerCase().includes(skuQ)) && (!asinQ || sku.asin.toLowerCase().includes(asinQ)),
                })),
        }))
        .filter((p) => p.skus.length > 0 && (!hasTextQuery || p.skus.some((sku) => sku.skuMatched)));
});

const totalSkuCount = computed(() => groupProducts.value.reduce((sum, p) => sum + p.skus.length, 0));
const matchedSkuCount = computed(() => {
    const hasTextQuery = Boolean(skuQuery.value.trim() || asinQuery.value.trim());
    return filteredProducts.value.reduce((sum, p) => sum + (hasTextQuery ? p.skus.filter((sku) => sku.skuMatched).length : p.skus.length), 0);
});

function isZero(stock: StockQuantities, asin: string): boolean {
    return stockTotal(stock, asin) === 0;
}

function amazonStockTotal(stock: StockQuantities): number | null {
    return amazonTotal(stock);
}

function bossStockTotal(stock: StockQuantities): number | null {
    return bossTotal(stock);
}

function stockTotal(stock: StockQuantities, asin: string): number | null {
    return survey.value ? skuTotal(stock, asin, survey.value) : null;
}

function groupedStockState(quantity: number | null): StockState {
    return stockState(quantity, { low: true });
}

function amazonStockState(stock: StockQuantities, asin: string): StockState {
    return survey.value ? amazonStockStateOf(stock, asin, survey.value) : "pending";
}

function plainStockState(quantity: number | null): StockState {
    return stockState(quantity);
}

function skuRowState(matched: boolean | undefined, stock: StockQuantities, asin: string): "matched" | "all-zero" | undefined {
    if (matched) return "matched";
    return isZero(stock, asin) ? "all-zero" : undefined;
}

/** 倉庫別の構成バーの区間。総数が未確定なら null */
function compositionOf(stock: StockQuantities, asin: string): { key: string; width: number }[] | null {
    const total = stockTotal(stock, asin);
    if (total === null) return null;
    if (total === 0) return [];
    return [
        { key: "bg-wh-amazon", value: hasAmazonStock.value ? (amazonStockTotal(stock) ?? 0) : 0 },
        { key: "bg-wh-boss", value: bossStockTotal(stock) ?? 0 },
        { key: "bg-wh-free", value: stock.freeStock ?? 0 },
        { key: "bg-wh-ec", value: stock.ecStock ?? 0 },
    ]
        .filter((segment) => segment.value > 0)
        .map((segment) => ({ key: segment.key, width: (segment.value / total) * 100 }));
}

const conditionOperatorOptions = [
    { value: "and" as const, label: "AND" },
    { value: "or" as const, label: "OR" },
];

function closeRerunMenu() {
    if (rerunRunning.value) return;
    rerunMenuOpen.value = false;
    rerunError.value = "";
}

async function rerunSurvey() {
    const currentSurvey = survey.value;
    const settingId = rerunSettingId.value;
    if (!currentSurvey || !settingId || !canRerun.value) return;

    rerunError.value = "";
    rerunRunning.value = true;
    try {
        const rerunSurveyResult = await surveysStore.rerunImport(currentSurvey.id, settingId);
        toast.push("調査結果を再照合しました");
        rerunSettingId.value = null;
        rerunMenuOpen.value = false;
        await router.push({ name: "survey-result", params: { id: rerunSurveyResult.id }, query: { group: `setting:${settingId}` } });
    } catch (error) {
        if (axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
            rerunError.value = errors ? (Object.values(errors).flat()[0] ?? error.response?.data?.message) : error.response?.data?.message;
        }
        rerunError.value ||= "再照合に失敗しました。時間をおいて再度お試しください。";
        toast.push("再照合に失敗しました", "error");
    } finally {
        rerunRunning.value = false;
    }
}

async function exportExcel(scope: "all" | "filtered") {
    if (!survey.value) return;
    if ((scope === "filtered" && matchedSkuCount.value === 0) || (selectedGroupKey.value && groupProducts.value.length === 0)) {
        toast.push("出力対象のSKUがありません", "error");
        return;
    }
    const hasTextQuery = Boolean(skuQuery.value.trim() || asinQuery.value.trim());
    const skuIds =
        scope === "filtered"
            ? filteredProducts.value.flatMap((product) => product.skus.filter((sku) => !hasTextQuery || sku.skuMatched).map((sku) => Number(sku.id)))
            : selectedGroupKey.value
              ? groupProducts.value.flatMap((product) => product.skus.map((sku) => Number(sku.id)))
              : undefined;

    try {
        const response = await axios.get(`/api/surveys/${survey.value.id}/export`, {
            params: skuIds ? { sku_ids: skuIds } : undefined,
            responseType: "blob",
        });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement("a");
        link.href = url;
        link.download = `${formatDateTime(survey.value.executedAt).replace(/\D/g, "").slice(0, 12)}_在庫調査結果.xlsx`;
        link.click();
        URL.revokeObjectURL(url);
        toast.push(scope === "all" ? "全件の在庫調査結果をExcel出力しました" : `絞込結果（${matchedSkuCount.value}件）をExcel出力しました`);
    } catch {
        toast.push("Excel出力に失敗しました", "error");
    }
}
</script>

<template>
    <BaseCard v-if="!survey" :padded="false">
        <BaseEmpty icon="search_off" title="指定された調査結果が見つかりません" description="削除された可能性があります。">
            <BaseButton variant="primary" @click="router.push({ name: 'survey-history' })">調査履歴に戻る</BaseButton>
        </BaseEmpty>
    </BaseCard>

    <div v-else class="flex flex-col gap-4">
        <div class="flex flex-col gap-2">
            <RouterLink :to="{ name: 'survey-history' }" class="inline-flex w-fit items-center gap-0.5 text-xs text-muted hover:text-ink">
                <AppIcon name="chevron_left" :size="16" />
                調査履歴に戻る
            </RouterLink>
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">在庫調査結果</h1>
                    <span class="font-mono text-xs text-muted">{{ formatDateTime(survey.executedAt).slice(0, 10) }}</span>
                    <BaseBadge v-if="isLatest" tone="brand">最新</BaseBadge>
                </div>
                <div class="flex flex-wrap gap-2 xl:ml-auto">
                    <BaseButton data-testid="refresh-survey" variant="secondary" icon="refresh" :loading="refreshingSurvey" @click="refreshSurvey">最新の在庫を再読込</BaseButton>
                    <BaseButton data-testid="rerun-menu-button" variant="secondary" icon="compare_arrows" :disabled="!hasReusableFiles" :aria-expanded="rerunMenuOpen" @click="rerunMenuOpen = true"
                        >保存ファイルで再照合</BaseButton
                    >
                    <BaseButton variant="secondary" icon="table_view" @click="exportExcel('filtered')">絞込結果をExcel出力</BaseButton>
                    <BaseButton variant="primary" icon="download" @click="exportExcel('all')">全件をExcel出力</BaseButton>
                </div>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-[11.5px] text-muted">
                <p
                    >在庫基準日時: <span class="font-mono text-ink-2">{{ formatDateTime(survey.executedAt) }}</span></p
                >
                <p v-if="survey.createdAt"
                    >結果作成日時: <span class="font-mono text-ink-2">{{ formatDateTime(survey.createdAt) }}</span></p
                >
                <p v-if="survey.updatedAt && survey.updatedAt !== survey.createdAt"
                    >結果更新日時: <span class="font-mono text-ink-2">{{ formatDateTime(survey.updatedAt) }}</span></p
                >
            </div>
        </div>

        <div v-if="summary" class="grid grid-cols-2 gap-3 xl:grid-cols-4" data-survey-summary>
            <StatCard title="対象" :value="summary.skuCount" :unit="`SKU ／ ${summary.productCount} 品番`" :note="compareWithPrevious('skuCount')" />
            <StatCard title="全倉庫ゼロ" :value="summary.allZeroCount" unit="SKU" :note="compareWithPrevious('allZeroCount')" alert data-summary="all-zero" />
            <StatCard title="一部の倉庫でゼロ" :value="summary.partialZeroCount" unit="SKU" :note="compareWithPrevious('partialZeroCount')" data-summary="partial-zero" />
            <StatCard title="Amazon 在庫" :note="hasAmazonStock ? amazonMatchNote : ''" data-summary="amazon">
                <template #value>
                    <StatusLamp :state="amazonLamp.state" :label="amazonLamp.label" class="py-1.5 text-lg font-semibold" />
                </template>
            </StatCard>
        </div>

        <BaseAlert v-if="refreshError" tone="danger" title="調査結果の更新に失敗しました">{{ refreshError }}</BaseAlert>
        <div class="flex flex-col gap-1 text-[11.5px] text-muted">
            <p v-if="!hasReusableFiles">取込ファイルが削除されているため、再照合機能は利用できません。</p>
            <p v-if="amazonStatus === 'pending'">Amazon 在庫は取込待ちです。数量と在庫総数は取得後に確定します。この画面は30秒ごとに自動更新します。</p>
            <p v-else-if="amazonStatus === 'partial'">
                Amazon 在庫は一部SKU未取得です。未取得SKUの在庫総数は未確定です。Amazon 基準日時: {{ survey.amazonBasisAt ? formatDateTime(survey.amazonBasisAt) : "記録なし" }}
            </p>
            <p v-else-if="amazonStatus === 'complete'"
                >Amazon 基準日時: {{ survey.amazonBasisAt ? formatDateTime(survey.amazonBasisAt) : "記録なし" }}。ほかの在庫とは取得時刻が異なる同日の集計値です。</p
            >
            <p v-else-if="!includesAmazonInventory">この調査時点までに取得された Amazon 在庫履歴はありません。</p>
            <p>「取込待ち」「—」は取込待ち・未取得を示します。確定した在庫ゼロは「0」で表示します。</p>
        </div>

        <BaseModal
            :open="rerunMenuOpen"
            title="差異照合メニュー"
            description="現在残っている取込ファイルと全品番グループの対象品番を再照合し、元の在庫基準日時の調査結果を更新します。ファイルがない種類は取込待ちになります。"
            width="md"
            @close="closeRerunMenu"
        >
            <div class="flex flex-col gap-4">
                <p v-if="rerunSettingOptions.length === 0" class="text-[12.5px] text-muted">利用できる品番グループがありません。</p>
                <BaseSelect
                    data-testid="rerun-setting-select"
                    :model-value="rerunSettingId"
                    :options="rerunSettingOptions"
                    label="再照合後に表示する品番グループ"
                    placeholder="品番グループを選択してください"
                    :disabled="rerunSettingOptions.length === 0 || rerunRunning"
                    @update:model-value="(value) => (rerunSettingId = value === null ? null : String(value))"
                />
                <BaseAlert v-if="rerunError" tone="danger" title="再照合に失敗しました">{{ rerunError }}</BaseAlert>
            </div>
            <template #footer>
                <BaseButton variant="secondary" :disabled="rerunRunning" @click="closeRerunMenu">キャンセル</BaseButton>
                <BaseButton data-testid="rerun-button" variant="primary" icon="refresh" :disabled="!canRerun" :loading="rerunRunning" @click="rerunSurvey">
                    {{ rerunRunning ? "再照合中…" : "再照合を実行" }}
                </BaseButton>
            </template>
        </BaseModal>

        <BaseCard>
            <div class="flex flex-col gap-3">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                    <BaseSelect v-model="selectedGroupKey" :options="groupOptions" placeholder="すべての品番グループ" />
                    <BaseSelect v-model="brandFilter" :options="brandOptions" placeholder="すべてのブランド" />
                    <BaseSelect v-model="categoryFilter" :options="categoryOptions" placeholder="すべてのカテゴリ" />
                    <BaseInput v-model="productCodeQuery" class="[&_input]:font-mono" placeholder="品番で絞込" clearable clear-label="品番の絞込をクリア" />
                    <BaseInput v-model="skuQuery" class="[&_input]:font-mono" placeholder="SKUで絞込" clearable clear-label="SKUの絞込をクリア" />
                    <BaseInput v-model="asinQuery" class="[&_input]:font-mono" placeholder="ASINで絞込" clearable clear-label="ASINの絞込をクリア" />
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="mr-0.5 text-[11px] font-medium text-muted">在庫ゼロ</span>
                    <button
                        v-for="btn in zeroStockButtons"
                        :key="btn.key"
                        :data-stock-condition="btn.key"
                        :aria-pressed="activeConditions.includes(btn.key)"
                        type="button"
                        class="h-[26px] rounded-chip border px-3 text-[11.5px] transition-colors hover:cursor-pointer"
                        :class="activeConditions.includes(btn.key) ? 'border-signal-border bg-signal font-semibold text-ink' : 'border-line-strong bg-surface text-ink-2 hover:bg-surface-2'"
                        @click="toggleCondition(btn.key)"
                    >
                        {{ btn.label }}
                    </button>
                    <span class="mr-0.5 ml-3 text-[11px] font-medium text-muted">在庫あり</span>
                    <button
                        v-for="btn in hasStockButtons"
                        :key="btn.key"
                        :data-stock-condition="btn.key"
                        :aria-pressed="activeConditions.includes(btn.key)"
                        type="button"
                        class="h-[26px] rounded-chip border px-3 text-[11.5px] transition-colors hover:cursor-pointer"
                        :class="activeConditions.includes(btn.key) ? 'border-ink bg-ink font-semibold text-white' : 'border-line-strong bg-surface text-ink-2 hover:bg-surface-2'"
                        @click="toggleCondition(btn.key)"
                    >
                        {{ btn.label }}
                    </button>
                    <span class="mr-0.5 ml-3 text-[11px] font-medium text-muted">結合</span>
                    <SegmentedControl v-model="conditionOperator" data-condition-operator :options="conditionOperatorOptions" label="在庫条件の結合方法" size="sm" />
                    <span class="ml-auto text-[11.5px] text-muted">
                        該当 <span class="font-num text-lg font-semibold text-ink">{{ matchedSkuCount }}</span> ／ {{ totalSkuCount }} SKU
                    </span>
                    <BaseButton v-if="hasActiveFilters" data-clear-filters variant="ghost" size="sm" @click="clearFilters">条件をクリア</BaseButton>
                </div>
            </div>
        </BaseCard>

        <BaseCard v-if="filteredProducts.length === 0" :padded="false">
            <BaseEmpty icon="filter_alt_off" title="条件に一致するSKUがありません" description="0件です。絞込条件を見直してください。" />
        </BaseCard>

        <BaseCard v-for="product in filteredProducts" :key="product.productCode" v-memo="[product]" :padded="false" class="survey-product-card">
            <div class="flex flex-col gap-2 border-b border-line-2 px-4 py-2.5 lg:flex-row lg:items-center lg:gap-3">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <p class="font-mono text-[15px] leading-relaxed font-semibold uppercase">{{ product.productCode }}</p>
                    <span class="font-mono text-[11px] text-muted">{{ product.parentAsin || "—" }}</span>
                    <BaseBadge>{{ product.brand }}</BaseBadge>
                    <BaseBadge>{{ product.category }}</BaseBadge>
                </div>
                <div class="w-full lg:ml-auto lg:w-[300px]">
                    <MemoField :model-value="product.memo" framed placeholder="品番のメモを追加" @save="(v) => surveysStore.updateProductMemo(survey!.id, product.productCode, v)" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[880px] table-fixed text-right tabular-nums">
                    <colgroup>
                        <col class="w-[210px]" />
                        <col v-if="hasAmazonStock" class="w-[92px]" />
                        <col class="w-[92px]" />
                        <col class="w-[92px]" />
                        <col class="w-[92px]" />
                        <col v-if="hasAmazonStock" class="w-[84px]" />
                        <col class="w-[136px]" />
                        <col />
                    </colgroup>
                    <thead class="border-b border-line text-[11px] text-muted">
                        <tr class="align-bottom">
                            <th class="py-2 pr-3 pl-[18px] text-left font-semibold">SKU<small class="block text-[10px] font-normal text-faint">ASIN</small></th>
                            <th v-if="hasAmazonStock" class="px-3 py-2 font-semibold">
                                <span class="mr-1.5 inline-block h-2 w-2 rounded-xs bg-wh-amazon" aria-hidden="true"></span>Amazon<small class="block text-[10px] font-normal text-faint"
                                    >自社／FBA</small
                                >
                            </th>
                            <th class="px-3 py-2 font-semibold">
                                <span class="mr-1.5 inline-block h-2 w-2 rounded-xs bg-wh-boss" aria-hidden="true"></span>BOSS<small class="block text-[10px] font-normal text-faint">自社／RFC</small>
                            </th>
                            <th class="px-3 py-2 font-semibold"><span class="mr-1.5 inline-block h-2 w-2 rounded-xs bg-wh-free" aria-hidden="true"></span>フリー在庫</th>
                            <th class="px-3 py-2 font-semibold"><span class="mr-1.5 inline-block h-2 w-2 rounded-xs bg-wh-ec" aria-hidden="true"></span>ECストック</th>
                            <th v-if="hasAmazonStock" class="px-3 py-2 font-semibold">総数</th>
                            <th class="px-3 py-2 text-left font-semibold">倉庫別の構成</th>
                            <th class="px-3 py-2 text-left font-semibold">メモ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="sku in product.skus"
                            :key="sku.skuCode"
                            :data-row-state="skuRowState(sku.skuMatched, sku.stock, sku.asin)"
                            class="border-t border-line-2 first:border-t-0"
                            :class="{
                                'bg-signal-row': skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero',
                                'bg-match': skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'matched',
                                'hover:bg-surface-2': !skuRowState(sku.skuMatched, sku.stock, sku.asin),
                            }"
                        >
                            <td data-sku-cell class="relative py-1.5 pr-3 pl-[18px] text-left">
                                <span
                                    v-if="skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero'"
                                    class="absolute top-[7px] bottom-[7px] left-1.5 w-[5px] rounded-[3px] bg-hazard"
                                    aria-hidden="true"
                                ></span>
                                <span v-else-if="sku.skuMatched" class="absolute top-[9px] bottom-[9px] left-[7px] w-[3px] rounded-xs bg-ink" aria-hidden="true"></span>
                                <span class="font-mono text-sm font-medium uppercase">{{ sku.skuCode }}</span>
                                <span
                                    v-if="skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero'"
                                    class="ml-1.5 rounded-chip bg-ink px-1.5 align-[1px] text-[9.5px] font-bold tracking-wide text-signal"
                                    >全倉庫ゼロ</span
                                >
                                <span class="block font-mono text-[10.5px] text-muted">{{ sku.asin || "ASIN未登録" }}</span>
                            </td>
                            <td v-if="hasAmazonStock" data-stock-field="amazon" :data-stock-state="amazonStockState(sku.stock, sku.asin)" aria-label="Amazon在庫合計" class="px-3 py-1.5">
                                <StockCell
                                    :quantity="amazonStockTotal(sku.stock)"
                                    :state="amazonStockState(sku.stock, sku.asin)"
                                    :breakdown="[sku.stock.amazonOwn, sku.stock.amazonFba]"
                                    pending-label="取込待ち"
                                    :strong="skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero'"
                                />
                            </td>
                            <td data-stock-field="boss" :data-stock-state="groupedStockState(bossStockTotal(sku.stock))" aria-label="BOSS在庫合計" class="px-3 py-1.5">
                                <StockCell
                                    :quantity="bossStockTotal(sku.stock)"
                                    :state="groupedStockState(bossStockTotal(sku.stock))"
                                    :breakdown="[sku.stock.bossOwn, sku.stock.bossRfc]"
                                    :strong="skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero'"
                                />
                            </td>
                            <td data-stock-field="freeStock" :data-stock-state="plainStockState(sku.stock.freeStock)" class="px-3 py-1.5">
                                <StockCell :quantity="sku.stock.freeStock" :state="plainStockState(sku.stock.freeStock)" :strong="skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero'" />
                            </td>
                            <td data-stock-field="ecStock" :data-stock-state="plainStockState(sku.stock.ecStock)" class="px-3 py-1.5">
                                <StockCell :quantity="sku.stock.ecStock" :state="plainStockState(sku.stock.ecStock)" :strong="skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero'" />
                            </td>
                            <td
                                v-if="hasAmazonStock"
                                data-stock-field="total"
                                :data-stock-state="plainStockState(stockTotal(sku.stock, sku.asin))"
                                class="px-3 py-1.5"
                                :class="skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero' ? 'bg-signal-row-strong' : 'bg-ink/[0.025]'"
                            >
                                <StockCell
                                    :quantity="stockTotal(sku.stock, sku.asin)"
                                    :state="plainStockState(stockTotal(sku.stock, sku.asin))"
                                    total
                                    :strong="skuRowState(sku.skuMatched, sku.stock, sku.asin) === 'all-zero'"
                                />
                            </td>
                            <td class="px-3 py-1.5 text-left" data-composition>
                                <span v-if="compositionOf(sku.stock, sku.asin) === null" class="text-[10.5px] font-semibold text-muted">{{
                                    amazonStockState(sku.stock, sku.asin) === "pending" ? "Amazon 取込後に表示" : "取込後に表示"
                                }}</span>
                                <span v-else class="flex h-[7px] w-28 gap-0.5 overflow-hidden rounded-xs" :class="compositionOf(sku.stock, sku.asin)!.length === 0 ? 'bg-ink/[0.07]' : ''">
                                    <i v-for="segment in compositionOf(sku.stock, sku.asin)" :key="segment.key" class="block h-full" :class="segment.key" :style="{ width: `${segment.width}%` }"></i>
                                </span>
                            </td>
                            <td class="px-3 py-1.5 text-left text-[11.5px]">
                                <MemoField :model-value="sku.memo" placeholder="メモを追加" @save="(v) => surveysStore.updateSkuMemo(survey!.id, sku.skuCode, v)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </BaseCard>
    </div>
</template>

<style>
.survey-product-card {
    content-visibility: auto;
    contain-intrinsic-size: auto 16rem;
}
</style>
