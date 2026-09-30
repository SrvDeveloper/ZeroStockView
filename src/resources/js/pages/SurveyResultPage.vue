<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseModal from "@/components/ui/BaseModal.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import MemoField from "@/components/ui/MemoField.vue";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { IMPORT_FILE_TYPES, type SelectOption, type StockQuantities } from "@/types";
import { formatDateTime } from "@/utils/format";

const props = defineProps<{ id: string }>();

const router = useRouter();
const importSettingsStore = useImportSettingsStore();
const surveysStore = useSurveysStore();
const toast = useUiStore();

const survey = computed(() => surveysStore.getSurvey(props.id));
const isLatest = computed(() => surveysStore.latestSurvey?.id === props.id);
const rerunSettingId = ref<string | null>(null);
const rerunMenuOpen = ref(false);
const rerunRunning = ref(false);
const rerunError = ref("");
const hasReusableFiles = computed(() => {
    const fileTypes = new Set(survey.value?.files.map((file) => file.type) ?? []);
    return IMPORT_FILE_TYPES.every(({ type }) => fileTypes.has(type));
});
const rerunSettingOptions = computed<SelectOption[]>(() =>
    importSettingsStore.settings
        .filter((setting) => setting.name !== survey.value?.importSettingName)
        .map((setting) => ({ value: setting.id, label: `${setting.name}（${setting.productCodes.length}件）` })),
);
const canRerun = computed(() => hasReusableFiles.value && rerunSettingId.value !== null && !rerunRunning.value);

const brandOptions = computed<SelectOption[]>(() => Array.from(new Set(survey.value?.products.map((p) => p.brand) ?? [])).map((b) => ({ value: b, label: b })));
const categoryOptions = computed<SelectOption[]>(() => Array.from(new Set(survey.value?.products.map((p) => p.category) ?? [])).map((c) => ({ value: c, label: c })));

const brandFilter = ref<string | null>(null);
const categoryFilter = ref<string | null>(null);
const productCodeQuery = ref("");
const skuQuery = ref("");
const asinQuery = ref("");
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
});

onBeforeUnmount(() => {
    window.removeEventListener("keydown", updateControlKeyState);
    window.removeEventListener("keyup", updateControlKeyState);
    window.removeEventListener("blur", clearControlKeyState);
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
                return stock.amazonOwn === 0 && stock.amazonFba === 0;
            case "bossZero":
                return stock.bossOwn === 0 && stock.bossRfc === 0;
            case "freeZero":
                return stock.freeStock === 0;
            case "ecZero":
                return stock.ecStock === 0;
            case "amazonHas":
                return stock.amazonOwn + stock.amazonFba > 0;
            case "bossHas":
                return stock.bossOwn + stock.bossRfc > 0;
            case "freeHas":
                return stock.freeStock > 0;
            case "ecHas":
                return stock.ecStock > 0;
            default:
                return true;
        }
    };

    return conditionOperator.value === "and" ? activeConditions.value.every(matches) : activeConditions.value.some(matches);
}

const hasActiveFilters = computed(() => Boolean(brandFilter.value || categoryFilter.value || productCodeQuery.value || skuQuery.value || asinQuery.value) || activeConditions.value.length > 0);

function clearFilters() {
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

    return s.products
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

const totalSkuCount = computed(() => survey.value?.products.reduce((sum, p) => sum + p.skus.length, 0) ?? 0);
const matchedSkuCount = computed(() => {
    const hasTextQuery = Boolean(skuQuery.value.trim() || asinQuery.value.trim());
    return filteredProducts.value.reduce((sum, p) => sum + (hasTextQuery ? p.skus.filter((sku) => sku.skuMatched).length : p.skus.length), 0);
});

function isZero(stock: StockQuantities): boolean {
    return Object.values(stock).every((v) => v === 0);
}

function amazonStockTotal(stock: StockQuantities): number {
    return stock.amazonOwn + stock.amazonFba;
}

function bossStockTotal(stock: StockQuantities): number {
    return stock.bossOwn + stock.bossRfc;
}

function stockTotal(stock: StockQuantities): number {
    return stock.amazonOwn + stock.amazonFba + stock.bossOwn + stock.bossRfc + stock.freeStock + stock.ecStock;
}

function isLowStock(quantity: number): boolean {
    return quantity > 0 && quantity <= 5;
}

function groupedStockClass(quantity: number): string {
    if (quantity === 0) return "bg-rose-50 font-semibold text-rose-400";
    if (isLowStock(quantity)) return "bg-amber-50 font-semibold text-amber-700";
    return "font-semibold text-slate-900";
}

function amazonStockClass(quantity: number, asin: string): string {
    if (!asin.trim()) return "text-slate-300";
    return groupedStockClass(quantity);
}

function stockClass(quantity: number): string {
    if (quantity === 0) return "text-slate-300";
    return "font-semibold text-slate-900";
}

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
        toast.push("別の取込設定で再照合しました");
        rerunSettingId.value = null;
        rerunMenuOpen.value = false;
        await router.push({ name: "survey-result", params: { id: rerunSurveyResult.id } });
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
    const hasTextQuery = Boolean(skuQuery.value.trim() || asinQuery.value.trim());
    const skuIds = scope === "filtered" ? filteredProducts.value.flatMap((product) => product.skus.filter((sku) => !hasTextQuery || sku.skuMatched).map((sku) => Number(sku.id))) : undefined;

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

    <div v-else class="flex flex-col gap-6" :class="{ 'stock-breakdown-enabled': isControlPressed }">
        <div class="flex flex-col gap-2">
            <RouterLink :to="{ name: 'survey-history' }" class="inline-flex w-fit items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
                調査履歴に戻る
            </RouterLink>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold text-slate-900">在庫調査結果</h1>
                    <BaseBadge v-if="isLatest" tone="brand">最新</BaseBadge>
                </div>
                <div class="flex flex-wrap gap-2">
                    <BaseButton data-testid="rerun-menu-button" variant="secondary" icon="compare_arrows" :disabled="!hasReusableFiles" :aria-expanded="rerunMenuOpen" @click="rerunMenuOpen = true"
                        >別の取込設定で再照合</BaseButton
                    >
                    <BaseButton variant="secondary" icon="table_view" @click="exportExcel('filtered')">絞込結果をExcel出力</BaseButton>
                    <BaseButton variant="primary" icon="download" @click="exportExcel('all')">全件をExcel出力</BaseButton>
                </div>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-500">
                <p>調査日時: {{ formatDateTime(survey.executedAt) }}</p>
                <p>
                    取込設定: <span class="font-medium text-slate-700">{{ survey.importSettingName || "記録なし" }}</span>
                </p>
            </div>
        </div>
        <p v-if="!hasReusableFiles" class="text-xs text-slate-400">取込ファイルが削除されているため、再照合機能は利用できません。</p>

        <BaseModal :open="rerunMenuOpen" title="差異照合メニュー" description="保存済みの取込ファイルを使い、選択した設定で新しい調査結果を作成します。" width="md" @close="closeRerunMenu">
            <div class="flex flex-col gap-4">
                <p v-if="rerunSettingOptions.length === 0" class="text-sm text-slate-500">利用できる別の取込設定がありません。</p>
                <BaseSelect
                    data-testid="rerun-setting-select"
                    :model-value="rerunSettingId"
                    :options="rerunSettingOptions"
                    label="再照合に利用する取込設定"
                    placeholder="取込設定を選択してください"
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
            <div class="flex flex-col gap-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <BaseSelect v-model="brandFilter" :options="brandOptions" placeholder="すべてのブランド" />
                    <BaseSelect v-model="categoryFilter" :options="categoryOptions" placeholder="すべてのカテゴリ" />
                    <BaseInput v-model="productCodeQuery" placeholder="品番で絞込" clearable clear-label="品番の絞込をクリア" />
                    <BaseInput v-model="skuQuery" placeholder="SKUで絞込" clearable clear-label="SKUの絞込をクリア" />
                    <BaseInput v-model="asinQuery" placeholder="ASINで絞込" clearable clear-label="ASINの絞込をクリア" />
                </div>

                <div class="flex flex-wrap items-center gap-1">
                    <span class="text-xs font-medium text-slate-400">在庫ゼロ:</span>
                    <button
                        v-for="btn in ZERO_STOCK_BUTTONS"
                        :key="btn.key"
                        :data-stock-condition="btn.key"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                        :class="activeConditions.includes(btn.key) ? 'border-rose-500 bg-rose-500 text-white' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
                        @click="toggleCondition(btn.key)"
                    >
                        {{ btn.label }}
                    </button>
                    <span class="ml-3 text-xs font-medium text-slate-400">在庫あり:</span>
                    <button
                        v-for="btn in HAS_STOCK_BUTTONS"
                        :key="btn.key"
                        :data-stock-condition="btn.key"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                        :class="activeConditions.includes(btn.key) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
                        @click="toggleCondition(btn.key)"
                    >
                        {{ btn.label }}
                    </button>
                    <span class="ml-3 text-xs font-medium text-slate-400">結合:</span>
                    <div class="inline-flex" role="group" aria-label="在庫条件の結合方法">
                        <button
                            type="button"
                            data-condition-operator="and"
                            :aria-pressed="conditionOperator === 'and'"
                            class="rounded-l-md w-14 border border-slate-300 px-2.5 py-1 text-xs font-semibold transition-colors"
                            :class="conditionOperator === 'and' ? 'relative z-10 border-primary-500 bg-primary-50 text-primary-700' : 'bg-white text-slate-500 hover:bg-slate-50'"
                            @click="conditionOperator = 'and'"
                            >AND</button
                        >
                        <button
                            type="button"
                            data-condition-operator="or"
                            :aria-pressed="conditionOperator === 'or'"
                            class="-ml-px w-14 rounded-r-md border border-slate-300 px-2.5 py-1 text-xs font-semibold transition-colors"
                            :class="conditionOperator === 'or' ? 'relative z-10 border-primary-500 bg-primary-50 text-primary-700' : 'bg-white text-slate-500 hover:bg-slate-50'"
                            @click="conditionOperator = 'or'"
                            >OR</button
                        >
                    </div>
                    <button v-if="hasActiveFilters" type="button" data-clear-filters class="ml-auto text-xs font-medium text-primary-600 hover:underline" @click="clearFilters">条件をクリア</button>
                </div>

                <p class="text-xs text-slate-400">
                    該当SKU: <span class="font-semibold text-slate-700">{{ matchedSkuCount }}</span> / 全{{ totalSkuCount }}件
                    <span class="ml-3">Ctrlキーを押しながらAmazon・BOSSの数値にマウスを置くと内訳を表示します</span>
                </p>
            </div>
        </BaseCard>

        <BaseCard v-if="filteredProducts.length === 0" :padded="false">
            <BaseEmpty icon="filter_alt_off" title="条件に一致するSKUがありません" description="0件です。絞込条件を見直してください。" />
        </BaseCard>

        <BaseCard v-for="product in filteredProducts" :key="product.productCode" v-memo="[product]" :padded="false" class="survey-product-card">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-3.5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                        <p class="text-sm font-semibold text-slate-900">{{ product.productCode }}</p>
                        <span class="text-xs text-slate-400">{{ product.parentAsin || "—" }}</span>
                    </div>
                    <div class="mt-1 flex gap-1.5">
                        <BaseBadge>{{ product.brand }}</BaseBadge>
                        <BaseBadge>{{ product.category }}</BaseBadge>
                    </div>
                </div>
                <div class="w-full lg:w-80">
                    <label class="mb-1 block text-xs font-medium text-slate-400">メモ</label>
                    <MemoField :model-value="product.memo" placeholder="メモを入力" @save="(v) => surveysStore.updateProductMemo(survey!.id, product.productCode, v)" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-200 table-fixed text-left text-sm">
                    <colgroup>
                        <col class="w-40" />
                        <col class="w-24" />
                        <col class="w-24" />
                        <col class="w-24" />
                        <col class="w-24" />
                        <col class="w-24" />
                        <col />
                    </colgroup>
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-2">SKU</th>
                            <th class="border-l border-slate-200 px-3 py-2 text-center">Amazon</th>
                            <th class="border-l border-slate-200 px-3 py-2 text-center">BOSS</th>
                            <th class="border-l border-slate-200 px-3 py-2 text-center">フリー在庫</th>
                            <th class="border-l border-slate-200 px-3 py-2 text-center">ECストック</th>
                            <th class="border-l border-slate-200 px-3 py-2 text-center">在庫総数</th>
                            <th class="border-l border-slate-200 px-3 py-2">メモ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="sku in product.skus" :key="sku.skuCode" class="group" :class="sku.skuMatched ? 'bg-sky-50' : isZero(sku.stock) ? 'bg-slate-50/60' : ''">
                            <td data-sku-cell class="px-3 py-2.5 transition-colors group-hover:bg-slate-100">
                                <div class="flex flex-col">
                                    <span class="font-semibold" :class="sku.skuMatched ? 'text-sky-800' : 'text-slate-900'">{{ sku.skuCode }}</span>
                                    <span class="flex items-center text-xs" :class="sku.skuMatched ? 'text-sky-700' : 'text-slate-400'">{{ sku.asin }}</span>
                                </div>
                            </td>
                            <td
                                data-stock-field="amazon"
                                tabindex="0"
                                aria-label="Amazon在庫合計"
                                class="stock-total-cell border-l border-slate-200 px-3 py-2.5 text-center tabular-nums"
                                :class="amazonStockClass(amazonStockTotal(sku.stock), sku.asin)"
                            >
                                <span class="stock-total-value">{{ amazonStockTotal(sku.stock) }}</span>
                                <div class="stock-breakdown-tooltip" role="tooltip">
                                    <p class="mb-1.5 text-left text-[11px] font-semibold text-slate-500">Amazon内訳</p>
                                    <div class="grid grid-cols-2 divide-x divide-slate-200">
                                        <div class="pr-2 text-center">
                                            <span class="block text-[10px] text-slate-400">自社</span>
                                            <strong class="text-sm text-slate-900">{{ sku.stock.amazonOwn }}</strong>
                                        </div>
                                        <div class="pl-2 text-center">
                                            <span class="block text-[10px] text-slate-400">FBA</span>
                                            <strong class="text-sm text-slate-900">{{ sku.stock.amazonFba }}</strong>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td
                                data-stock-field="boss"
                                tabindex="0"
                                aria-label="BOSS在庫合計"
                                class="stock-total-cell border-l border-slate-200 px-3 py-2.5 text-center tabular-nums"
                                :class="groupedStockClass(bossStockTotal(sku.stock))"
                            >
                                <span class="stock-total-value">{{ bossStockTotal(sku.stock) }}</span>
                                <div class="stock-breakdown-tooltip" role="tooltip">
                                    <p class="mb-1.5 text-left text-[11px] font-semibold text-slate-500">BOSS内訳</p>
                                    <div class="grid grid-cols-2 divide-x divide-slate-200">
                                        <div class="pr-2 text-center">
                                            <span class="block text-[10px] text-slate-400">自社</span>
                                            <strong class="text-sm text-slate-900">{{ sku.stock.bossOwn }}</strong>
                                        </div>
                                        <div class="pl-2 text-center">
                                            <span class="block text-[10px] text-slate-400">RFC</span>
                                            <strong class="text-sm text-slate-900">{{ sku.stock.bossRfc }}</strong>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td data-stock-field="freeStock" class="border-l border-slate-200 px-3 py-2.5 text-center tabular-nums" :class="stockClass(sku.stock.freeStock)">
                                {{ sku.stock.freeStock }}
                            </td>
                            <td data-stock-field="ecStock" class="border-l border-slate-200 px-3 py-2.5 text-center tabular-nums" :class="stockClass(sku.stock.ecStock)">
                                {{ sku.stock.ecStock }}
                            </td>
                            <td data-stock-field="total" class="border-l border-slate-200 px-3 py-2.5 text-center tabular-nums" :class="stockClass(stockTotal(sku.stock))">
                                {{ stockTotal(sku.stock) }}
                            </td>
                            <td class="border-l border-slate-200 px-3 py-2.5">
                                <MemoField :model-value="sku.memo" placeholder="メモを入力" @save="(v) => surveysStore.updateSkuMemo(survey!.id, sku.skuCode, v)" />
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
    z-index: 30;
    outline: 2px solid #6366f1;
    outline-offset: -2px;
}

.stock-breakdown-tooltip {
    position: absolute;
    right: auto;
    bottom: 0;
    left: calc(100% + 0.5rem);
    z-index: 40;
    width: 9rem;
    padding: 0.625rem 0.75rem;
    color: #334155;
    visibility: hidden;
    pointer-events: none;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    box-shadow: 0 10px 25px -5px rgb(15 23 42 / 0.2);
    opacity: 0;
    transform: translateX(-0.25rem);
    transition:
        opacity 120ms ease,
        transform 120ms ease,
        visibility 0s linear 120ms;
}

.stock-breakdown-enabled .stock-total-cell:hover .stock-breakdown-tooltip {
    visibility: visible;
    opacity: 1;
    transform: translateX(0);
    transition-delay: 500ms;
}

.stock-breakdown-enabled .stock-total-cell:focus .stock-breakdown-tooltip,
.stock-breakdown-enabled .stock-total-cell:focus-within .stock-breakdown-tooltip {
    visibility: visible;
    opacity: 1;
    transform: translateX(0);
    transition-delay: 0ms;
}
</style>
