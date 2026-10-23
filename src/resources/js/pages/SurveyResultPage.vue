<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import SurveyFilesZipButton from "@/components/survey/SurveyFilesZipButton.vue";
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
import { ADMIN_ROLES, EDITOR_ROLES, canAccess } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { IMPORT_FILE_TYPES, type SelectOption, type StockQuantities, type Survey, type SurveyProductResult, type SurveySkuResult } from "@/types";
import { formatDateTime, formatShortDateTime } from "@/utils/format";
import { fileRemainingDays } from "@/utils/files";
import { amazonStockState as amazonStockStateOf, amazonTotal, bossTotal, previousSurvey, skuTotal, stockState, summarizeSurvey, type StockState } from "@/utils/stock";

const props = defineProps<{ id: string; initialGroup?: string }>();

const router = useRouter();
const auth = useAuthStore();
const importSettingsStore = useImportSettingsStore();
const surveysStore = useSurveysStore();
const toast = useUiStore();
const canEditMemo = computed(() => canAccess(auth.role, ADMIN_ROLES));
const canRerunSurvey = computed(() => canAccess(auth.role, EDITOR_ROLES));

const survey = computed(() => surveysStore.getSurvey(props.id));
/** 明細の取得状況。一覧から開いても URL を直接開いても、ここで明細を取得する */
const detailState = ref<"loading" | "ready" | "missing" | "error">("loading");

/** 明細を取得する。取得済みならそれを先に表示し、裏で最新に取り直す */
async function openSurvey(id: string) {
    const cached = surveysStore.getSurvey(id);
    detailState.value = cached ? "ready" : "loading";
    try {
        await surveysStore.loadSurvey(id, { force: cached !== undefined });
        if (props.id === id) detailState.value = "ready";
    } catch (error) {
        if (props.id !== id) return;
        if (axios.isAxiosError(error) && error.response?.status === 404) detailState.value = "missing";
        else if (cached) refreshError.value = "調査結果を更新できませんでした。再読み込みしてください。";
        else detailState.value = "error";
    }
}
const includesAmazonInventory = computed(() => survey.value?.includesAmazonInventory !== false);
const hasAmazonStock = computed(() => includesAmazonInventory.value || survey.value?.amazonSource === "sp_api");
const amazonStatus = computed(() => survey.value?.amazonInventoryStatus ?? (survey.value?.amazonSource === "sp_api" ? (survey.value.amazonBasisAt ? "complete" : "pending") : "legacy_file"));
const refreshingSurvey = ref(false);
const refreshError = ref("");
let refreshTimer: number | undefined;
watch(
    () => props.id,
    (id) => void openSurvey(id),
    { immediate: true },
);
/** 画面が見えているか。別のタブに移っている間は取り直さない */
const pageVisible = ref(document.visibilityState !== "hidden");
/** Amazon在庫の反映を待っている調査か（取込待ち・一部未取得）。反映済みの調査は取り直さない */
const waitingForAmazon = computed(() => survey.value?.amazonSource === "sp_api" && (amazonStatus.value === "pending" || amazonStatus.value === "partial"));
watch(
    [() => props.id, waitingForAmazon, pageVisible],
    ([, waiting, visible]) => {
        if (refreshTimer !== undefined) window.clearInterval(refreshTimer);
        refreshTimer = undefined;
        if (waiting && visible) refreshTimer = window.setInterval(() => void refreshSurvey(), 30000);
    },
    { immediate: true },
);

/** 画面に戻ったときは、待っている間に反映されていないかをすぐ確かめる */
function onVisibilityChange() {
    const visible = document.visibilityState !== "hidden";
    if (visible && !pageVisible.value && waitingForAmazon.value) void refreshSurvey();
    pageVisible.value = visible;
}

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
const isLatest = computed(() => surveysStore.latestSummary?.id === props.id);
const rerunSettingId = ref<string | null>(null);
const rerunMenuOpen = ref(false);
const rerunRunning = ref(false);
const rerunError = ref("");
/** 保存期限内の取込ファイルがある種類の数（BOSS・KEEP一覧表・在庫一覧照会表のうち） */
const savedFileTypeCount = computed(() => IMPORT_FILE_TYPES.filter(({ type }) => survey.value?.files.some((file) => file.type === type && fileRemainingDays(file.uploadedAt) > 0)).length);
const hasReusableFiles = computed(() => {
    const fileTypes = new Set(survey.value?.files.filter((file) => fileRemainingDays(file.uploadedAt) > 0).map((file) => file.type) ?? []);
    return IMPORT_FILE_TYPES.some(({ type }) => fileTypes.has(type));
});
const rerunSettingOptions = computed<SelectOption[]>(() => importSettingsStore.settings.map((setting) => ({ value: setting.id, label: `${setting.name}（${setting.productCodes.length}件）` })));
/** 旧5ファイル調査・取得方式が未確定の調査は、Amazon在庫の保存値を守るため再照合できない（API も断る） */
const isRerunnableSource = computed(() => survey.value?.amazonSource === "sp_api");
const rerunAvailable = computed(() => hasReusableFiles.value && isRerunnableSource.value);
const canRerun = computed(() => canRerunSurvey.value && rerunAvailable.value && !rerunRunning.value);

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

/** Ctrl キーを押している間だけ、Amazon と BOSS の内訳（自社／FBA、自社／RFC）を吹き出しで出す */
const isControlPressed = ref(false);

function updateControlKeyState(event: KeyboardEvent) {
    if (event.key === "Control") isControlPressed.value = event.type === "keydown";
}

function clearControlKeyState() {
    isControlPressed.value = false;
}

onMounted(() => {
    window.addEventListener("keydown", updateControlKeyState);
    window.addEventListener("keyup", updateControlKeyState);
    window.addEventListener("blur", clearControlKeyState);
    document.addEventListener("visibilitychange", onVisibilityChange);
});

onBeforeUnmount(() => {
    window.removeEventListener("keydown", updateControlKeyState);
    window.removeEventListener("keyup", updateControlKeyState);
    window.removeEventListener("blur", clearControlKeyState);
    document.removeEventListener("visibilitychange", onVisibilityChange);
    if (refreshTimer !== undefined) window.clearInterval(refreshTimer);
});

const summary = computed(() => (survey.value ? summarizeSurvey(survey.value) : null));
/** 前回比は、直前の調査の明細を取らずに一覧の集計値で比べる */
const previousSummary = computed(() => (survey.value ? previousSurvey(surveysStore.summaries, survey.value) : null));

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

/** BOSS 在庫を在庫変動履歴から再現した調査か（品番カードの v-memo に入れるため、真偽値にしておく） */
const bossFromHistory = computed(() => survey.value?.bossSource === "history");

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

const STOCK_KEYS = ["amazonOwn", "amazonFba", "bossOwn", "bossRfc", "freeStock", "ecStock"] as const;

/** 在庫表の1行。状態・合計・構成は行ごとに一度だけ計算し、テンプレートは読むだけにする */
interface SkuRowView {
    sku: SurveySkuResult;
    matched: boolean;
    rowState: "matched" | "all-zero" | undefined;
    amazonTotal: number | null;
    amazonState: StockState;
    bossTotal: number | null;
    bossState: StockState;
    freeState: StockState;
    ecState: StockState;
    total: number | null;
    totalState: StockState;
    composition: { key: string; width: number }[] | null;
    /** 画面に出る値を並べた文字列。メモの保存など、同じオブジェクトの書き換えも見分けるために値で持つ */
    snapshot: string;
}

interface ProductView {
    product: SurveyProductResult;
    rows: SkuRowView[];
    snapshot: string;
}

function buildRow(sku: SurveySkuResult, matched: boolean, current: Survey): SkuRowView {
    const stock = sku.stock;
    const amazon = amazonTotal(stock);
    const boss = bossTotal(stock);
    const total = skuTotal(stock, sku.asin, current);
    return {
        sku,
        matched,
        rowState: matched ? "matched" : total === 0 ? "all-zero" : undefined,
        amazonTotal: amazon,
        amazonState: amazonStockStateOf(stock, sku.asin, current),
        bossTotal: boss,
        bossState: stockState(boss, { low: true }),
        freeState: stockState(stock.freeStock),
        ecState: stockState(stock.ecStock),
        total,
        totalState: stockState(total),
        composition: compositionOf(stock, total),
        snapshot: JSON.stringify([sku.id, sku.skuCode, sku.asin, sku.memo, matched, ...STOCK_KEYS.map((key) => stock[key])]),
    };
}

/** 倉庫別の構成バーの区間。総数が未確定なら null */
function compositionOf(stock: StockQuantities, total: number | null): { key: string; width: number }[] | null {
    if (total === null) return null;
    if (total === 0) return [];
    return [
        { key: "bg-wh-amazon", value: hasAmazonStock.value ? (amazonTotal(stock) ?? 0) : 0 },
        { key: "bg-wh-boss", value: bossTotal(stock) ?? 0 },
        { key: "bg-wh-free", value: stock.freeStock ?? 0 },
        { key: "bg-wh-ec", value: stock.ecStock ?? 0 },
    ]
        .filter((segment) => segment.value > 0)
        .map((segment) => ({ key: segment.key, width: (segment.value / total) * 100 }));
}

/** 品番の表示が前回と同じか（再取得で別のオブジェクトになっても、中身が同じなら描き直さない） */
function productSnapshot(product: SurveyProductResult, rows: SkuRowView[]): string {
    return JSON.stringify([product.productCode, product.brand, product.category, product.parentAsin, product.memo, ...rows.map((row) => row.snapshot)]);
}

/**
 * 前回の計算で作った品番の表示。中身が変わっていない品番は同じオブジェクトを返し、品番カードの v-memo を効かせる
 * （30秒ごとの再取得やメモの保存で、表全体を描き直さないため）。Amazon在庫の扱いが変わったときは作り直す。
 */
let previousViews = new Map<string, ProductView>();
let previousViewMode = "";

const filteredProducts = computed<ProductView[]>(() => {
    const current = survey.value;
    if (!current) return [];
    const mode = `${current.id}|${current.amazonSource}|${current.includesAmazonInventory}`;
    if (mode !== previousViewMode) previousViews = new Map();
    const productQ = productCodeQuery.value.trim().toLowerCase();
    const skuQ = skuQuery.value.trim().toLowerCase();
    const asinQ = asinQuery.value.trim().toLowerCase();
    const hasTextQuery = Boolean(skuQ || asinQ);

    const views: ProductView[] = [];
    const nextViews = new Map<string, ProductView>();
    for (const product of groupProducts.value) {
        if (brandFilter.value && product.brand !== brandFilter.value) continue;
        if (categoryFilter.value && product.category !== categoryFilter.value) continue;
        if (productQ && !product.productCode.toLowerCase().includes(productQ)) continue;
        const rows = product.skus
            .filter((sku) => matchesConditions(sku.stock))
            .map((sku) => buildRow(sku, hasTextQuery && (!skuQ || sku.skuCode.toLowerCase().includes(skuQ)) && (!asinQ || sku.asin.toLowerCase().includes(asinQ)), current));
        if (rows.length === 0 || (hasTextQuery && !rows.some((row) => row.matched))) continue;

        const snapshot = productSnapshot(product, rows);
        const previous = previousViews.get(product.productCode);
        const view = previous?.snapshot === snapshot ? previous : { product, rows, snapshot };
        views.push(view);
        nextViews.set(product.productCode, view);
    }
    previousViews = nextViews;
    previousViewMode = mode;
    return views;
});

const totalSkuCount = computed(() => groupProducts.value.reduce((sum, p) => sum + p.skus.length, 0));
const matchedSkuCount = computed(() => {
    const hasTextQuery = Boolean(skuQuery.value.trim() || asinQuery.value.trim());
    return filteredProducts.value.reduce((sum, view) => sum + (hasTextQuery ? view.rows.filter((row) => row.matched).length : view.rows.length), 0);
});

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
    if (!currentSurvey || !canRerun.value) return;

    rerunError.value = "";
    rerunRunning.value = true;
    try {
        const rerunSurveyResult = await surveysStore.rerunImport(currentSurvey.id, settingId);
        toast.push("調査結果を再照合しました");
        rerunSettingId.value = null;
        rerunMenuOpen.value = false;
        await router.push({ name: "survey-result", params: { id: rerunSurveyResult.id }, query: settingId ? { group: `setting:${settingId}` } : {} });
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
            ? filteredProducts.value.flatMap((view) => view.rows.filter((row) => !hasTextQuery || row.matched).map((row) => Number(row.sku.id)))
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
    <div v-if="!survey && detailState === 'loading'" class="flex min-h-80 items-center justify-center gap-2 text-[12.5px] text-muted" role="status" data-survey-loading>
        <AppIcon name="progress_activity" :size="22" class="animate-spin text-ink-2" />
        調査結果を読み込んでいます...
    </div>
    <BaseCard v-else-if="!survey && detailState === 'error'" :padded="false">
        <BaseEmpty icon="error" title="調査結果を読み込めませんでした" description="時間をおいて再度お試しください。">
            <BaseButton variant="primary" icon="refresh" @click="openSurvey(props.id)">再試行</BaseButton>
        </BaseEmpty>
    </BaseCard>
    <BaseCard v-else-if="!survey" :padded="false">
        <BaseEmpty icon="search_off" title="指定された調査結果が見つかりません" description="削除された可能性があります。">
            <BaseButton variant="primary" @click="router.push({ name: 'survey-history' })">調査履歴に戻る</BaseButton>
        </BaseEmpty>
    </BaseCard>

    <div v-else class="flex flex-col gap-4" :class="{ 'stock-breakdown-enabled': isControlPressed }">
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
                    <BaseButton
                        v-if="canRerunSurvey"
                        data-testid="rerun-menu-button"
                        variant="secondary"
                        icon="compare_arrows"
                        :disabled="!rerunAvailable"
                        :aria-expanded="rerunMenuOpen"
                        @click="rerunMenuOpen = true"
                        >保存ファイルで再照合</BaseButton
                    >
                    <BaseButton variant="secondary" icon="table_view" @click="exportExcel('filtered')">絞込結果をExcel出力</BaseButton>
                    <BaseButton variant="primary" icon="download" @click="exportExcel('all')">全件をExcel出力</BaseButton>
                </div>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-[11.5px] text-muted">
                <p
                    >初回取込日時: <span class="font-mono text-ink-2">{{ formatDateTime(survey.executedAt) }}</span></p
                >
                <p v-if="survey.createdAt"
                    >結果作成日時: <span class="font-mono text-ink-2">{{ formatDateTime(survey.createdAt) }}</span></p
                >
                <p v-if="survey.updatedAt && survey.updatedAt !== survey.createdAt"
                    >結果更新日時: <span class="font-mono text-ink-2">{{ formatDateTime(survey.updatedAt) }}</span></p
                >
                <p v-if="bossFromHistory && survey.bossBasisAt" class="inline-flex items-center gap-1" data-boss-history-basis
                    ><AppIcon name="history" :size="14" class="text-ink-2" />BOSS: <span class="text-ink-2">履歴から再現（{{ formatShortDateTime(survey.bossBasisAt) }}時点）</span></p
                >
                <p class="inline-flex items-center gap-1.5" data-survey-saved-files
                    >取込ファイル: <span class="font-mono text-ink-2">{{ savedFileTypeCount }}/{{ IMPORT_FILE_TYPES.length }}</span> <SurveyFilesZipButton :survey="survey" class="-my-1"
                /></p>
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
        <div v-if="(canRerunSurvey && !rerunAvailable) || amazonStatus === 'pending' || amazonStatus === 'partial' || !hasAmazonStock" class="flex flex-col gap-1 text-[11.5px] text-muted">
            <p v-if="canRerunSurvey && !isRerunnableSource" data-testid="rerun-unavailable-legacy">Amazon在庫をファイルで取り込んだ旧方式の調査のため、再照合できません。</p>
            <p v-else-if="canRerunSurvey && !hasReusableFiles">再照合に使える取込ファイルがないため、再照合機能は利用できません。</p>
            <p v-if="amazonStatus === 'pending'">Amazon 在庫は取込待ちです。数量と在庫総数は取得後に確定します。この画面は30秒ごとに自動更新します。</p>
            <p v-else-if="amazonStatus === 'partial'">
                Amazon 在庫は一部SKU未取得です。未取得SKUの在庫総数は未確定です。Amazon 基準日時: {{ survey.amazonBasisAt ? formatDateTime(survey.amazonBasisAt) : "記録なし" }}
            </p>
            <p v-else-if="!hasAmazonStock">この調査時点までに取得された Amazon 在庫履歴はありません。</p>
        </div>

        <BaseModal
            v-if="canRerunSurvey"
            :open="rerunMenuOpen"
            title="差異照合メニュー"
            description="現在残っている取込ファイルを、CrossWalkerで有効な全品番と照合し直し、同じ日の調査結果を更新します（初回取込日時は変わりません）。ファイルがない種類は取込待ちになります。"
            width="md"
            @close="closeRerunMenu"
        >
            <div class="flex flex-col gap-4">
                <BaseSelect
                    data-testid="rerun-setting-select"
                    :model-value="rerunSettingId"
                    :options="rerunSettingOptions"
                    label="再照合後に表示する品番グループ"
                    placeholder="すべての品番グループ"
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

        <!-- 品番の表示（view）は中身が変わらない限り同じオブジェクトなので、v-memo でカードの描き直しを省く -->
        <BaseCard
            v-for="view in filteredProducts"
            :key="view.product.productCode"
            :data-product-code="view.product.productCode"
            v-memo="[view, canEditMemo, hasAmazonStock, bossFromHistory]"
            :padded="false"
            class="survey-product-card"
        >
            <div class="flex flex-col gap-2 border-b border-line-2 px-4 py-2.5 lg:flex-row lg:items-center lg:gap-3">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <p class="font-mono text-[15px] leading-relaxed font-semibold uppercase">{{ view.product.productCode }}</p>
                    <span class="font-mono text-[11px] text-muted">{{ view.product.parentAsin || "—" }}</span>
                    <BaseBadge>{{ view.product.brand }}</BaseBadge>
                    <BaseBadge>{{ view.product.category }}</BaseBadge>
                </div>
                <div class="w-full lg:ml-auto lg:w-[300px]">
                    <MemoField
                        v-if="canEditMemo"
                        :model-value="view.product.memo"
                        framed
                        placeholder="品番のメモを追加"
                        @save="(v) => surveysStore.updateProductMemo(survey!.id, view.product.productCode, v)"
                    />
                    <p v-else data-readonly-memo class="min-h-6 rounded-control bg-surface-2 px-2 py-1 text-[11.5px] text-ink-2">{{ view.product.memo || "メモなし" }}</p>
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
                            <th v-if="hasAmazonStock" class="px-3 py-2 font-semibold"> <span class="mr-1.5 inline-block h-2 w-2 rounded-xs bg-wh-amazon" aria-hidden="true"></span>Amazon </th>
                            <th class="px-3 py-2 font-semibold">
                                <span class="mr-1.5 inline-block h-2 w-2 rounded-xs bg-wh-boss" aria-hidden="true"></span>BOSS
                                <span
                                    v-if="bossFromHistory"
                                    data-boss-history-mark
                                    class="ml-0.5 inline-flex align-[-2px] text-ink-2"
                                    title="在庫変動履歴から再現した値です"
                                    aria-label="在庫変動履歴から再現した値です"
                                    ><AppIcon name="history" :size="13"
                                /></span>
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
                            v-for="row in view.rows"
                            :key="row.sku.skuCode"
                            :data-row-state="row.rowState"
                            class="border-t border-line-2 first:border-t-0"
                            :class="{
                                'bg-signal-row': row.rowState === 'all-zero',
                                'bg-match': row.rowState === 'matched',
                                'hover:bg-surface-2': !row.rowState,
                            }"
                        >
                            <td data-sku-cell class="relative py-1.5 pr-3 pl-[18px] text-left">
                                <span v-if="row.rowState === 'all-zero'" class="absolute top-[7px] bottom-[7px] left-1.5 w-[5px] rounded-[3px] bg-hazard" aria-hidden="true"></span>
                                <span v-else-if="row.matched" class="absolute top-[9px] bottom-[9px] left-[7px] w-[3px] rounded-xs bg-ink" aria-hidden="true"></span>
                                <span class="font-mono text-sm font-medium uppercase">{{ row.sku.skuCode }}</span>
                                <span class="block font-mono text-[10.5px] text-muted">{{ row.sku.asin || "ASIN未登録" }}</span>
                            </td>
                            <td v-if="hasAmazonStock" data-stock-field="amazon" :data-stock-state="row.amazonState" tabindex="0" aria-label="Amazon在庫合計" class="stock-total-cell px-3 py-1.5">
                                <StockCell :quantity="row.amazonTotal" :state="row.amazonState" pending-label="取込待ち" :strong="row.rowState === 'all-zero'" />
                                <span v-if="row.amazonTotal !== null" class="stock-breakdown-tooltip" role="tooltip">
                                    <span
                                        >自社 <b>{{ row.sku.stock.amazonOwn }}</b></span
                                    ><span aria-hidden="true">／</span
                                    ><span
                                        >FBA <b>{{ row.sku.stock.amazonFba }}</b></span
                                    >
                                </span>
                            </td>
                            <td data-stock-field="boss" :data-stock-state="row.bossState" tabindex="0" aria-label="BOSS在庫合計" class="stock-total-cell px-3 py-1.5">
                                <StockCell :quantity="row.bossTotal" :state="row.bossState" :strong="row.rowState === 'all-zero'" />
                                <span v-if="row.bossTotal !== null" class="stock-breakdown-tooltip" role="tooltip">
                                    <span
                                        >自社 <b>{{ row.sku.stock.bossOwn }}</b></span
                                    ><span aria-hidden="true">／</span
                                    ><span
                                        >RFC <b>{{ row.sku.stock.bossRfc }}</b></span
                                    >
                                </span>
                            </td>
                            <td data-stock-field="freeStock" :data-stock-state="row.freeState" class="px-3 py-1.5">
                                <StockCell :quantity="row.sku.stock.freeStock" :state="row.freeState" :strong="row.rowState === 'all-zero'" />
                            </td>
                            <td data-stock-field="ecStock" :data-stock-state="row.ecState" class="px-3 py-1.5">
                                <StockCell :quantity="row.sku.stock.ecStock" :state="row.ecState" :strong="row.rowState === 'all-zero'" />
                            </td>
                            <td
                                v-if="hasAmazonStock"
                                data-stock-field="total"
                                :data-stock-state="row.totalState"
                                class="px-3 py-1.5"
                                :class="row.rowState === 'all-zero' ? 'bg-signal-row-strong' : 'bg-ink/[0.025]'"
                            >
                                <StockCell :quantity="row.total" :state="row.totalState" total :strong="row.rowState === 'all-zero'" />
                            </td>
                            <td class="px-3 py-1.5 text-left" data-composition>
                                <span v-if="row.composition === null" class="text-[10.5px] font-semibold text-muted">{{ row.amazonState === "pending" ? "Amazon 取込後に表示" : "取込後に表示" }}</span>
                                <span v-else class="flex h-[7px] w-28 gap-0.5 overflow-hidden rounded-xs" :class="row.composition.length === 0 ? 'bg-ink/[0.07]' : ''">
                                    <i v-for="segment in row.composition" :key="segment.key" class="block h-full" :class="segment.key" :style="{ width: `${segment.width}%` }"></i>
                                </span>
                            </td>
                            <td class="px-3 py-1.5 text-left text-[11.5px]">
                                <MemoField v-if="canEditMemo" :model-value="row.sku.memo" placeholder="メモを追加" @save="(v) => surveysStore.updateSkuMemo(survey!.id, row.sku.skuCode, v)" />
                                <span v-else data-readonly-memo>{{ row.sku.memo || "メモなし" }}</span>
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

.stock-total-cell {
    position: relative;
}

.stock-breakdown-enabled .stock-total-cell {
    cursor: help;
}

.stock-breakdown-enabled .stock-total-cell:hover,
.stock-breakdown-enabled .stock-total-cell:focus-within {
    z-index: 30;
}

.stock-breakdown-enabled .stock-total-cell:focus-visible {
    outline: 2px solid var(--color-ink-2);
    outline-offset: -2px;
}

/* 数値の右に出す内訳の吹き出し。行の高さに収まるよう 1 行で表示する */
.stock-breakdown-tooltip {
    position: absolute;
    top: 50%;
    left: calc(100% - 0.375rem);
    z-index: 40;
    display: flex;
    align-items: baseline;
    gap: 0.375rem;
    padding: 0.25rem 0.625rem;
    font-size: 11px;
    white-space: nowrap;
    color: var(--color-ink-2);
    background: var(--color-surface);
    border: 1px solid var(--color-line);
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-float);
    visibility: hidden;
    opacity: 0;
    pointer-events: none;
    transform: translate(-0.25rem, -50%);
    transition:
        opacity 120ms ease,
        transform 120ms ease,
        visibility 0s linear 120ms;
}

.stock-breakdown-tooltip b {
    font-family: var(--font-num);
    font-size: 15px;
    font-weight: 600;
    color: var(--color-ink);
}

.stock-breakdown-enabled .stock-total-cell:hover .stock-breakdown-tooltip,
.stock-breakdown-enabled .stock-total-cell:focus .stock-breakdown-tooltip {
    visibility: visible;
    opacity: 1;
    transform: translate(0, -50%);
    transition-delay: 0ms;
}
</style>
