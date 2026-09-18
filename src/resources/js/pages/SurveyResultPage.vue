<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import MemoField from "@/components/ui/MemoField.vue";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { STOCK_FIELDS, type SelectOption, type StockQuantities } from "@/types";
import { formatDateTime } from "@/utils/format";

const props = defineProps<{ id: string }>();

const router = useRouter();
const surveysStore = useSurveysStore();
const toast = useUiStore();

const survey = computed(() => surveysStore.getSurvey(props.id));
const isLatest = computed(() => surveysStore.latestSurvey?.id === props.id);

const brandOptions = computed<SelectOption[]>(() => Array.from(new Set(survey.value?.products.map((p) => p.brand) ?? [])).map((b) => ({ value: b, label: b })));
const categoryOptions = computed<SelectOption[]>(() => Array.from(new Set(survey.value?.products.map((p) => p.category) ?? [])).map((c) => ({ value: c, label: c })));

const brandFilter = ref<string | null>(null);
const categoryFilter = ref<string | null>(null);
const productCodeQuery = ref("");
const skuQuery = ref("");
const asinQuery = ref("");

const ZERO_STOCK_BUTTONS = [
    { key: "amazonZero", label: "Amazon在庫ゼロ" },
    { key: "bossZero", label: "BOSS在庫ゼロ" },
    { key: "freeZero", label: "フリー在庫ゼロ" },
    { key: "ecZero", label: "ECストック在庫ゼロ" },
] as const;
const HAS_STOCK_BUTTONS = [
    { key: "freeHas", label: "フリー在庫あり" },
    { key: "ecHas", label: "ECストック在庫あり" },
] as const;

const activeConditions = ref<string[]>([]);

function toggleCondition(key: string) {
    const idx = activeConditions.value.indexOf(key);
    if (idx === -1) activeConditions.value.push(key);
    else activeConditions.value.splice(idx, 1);
}

function matchesConditions(stock: StockQuantities): boolean {
    return activeConditions.value.every((key) => {
        switch (key) {
            case "amazonZero":
                return stock.amazonOwn === 0 && stock.amazonFba === 0;
            case "bossZero":
                return stock.bossOwn === 0 && stock.bossRfc === 0;
            case "freeZero":
                return stock.freeStock === 0;
            case "ecZero":
                return stock.ecStock === 0;
            case "freeHas":
                return stock.freeStock > 0;
            case "ecHas":
                return stock.ecStock > 0;
            default:
                return true;
        }
    });
}

const hasActiveFilters = computed(() => Boolean(brandFilter.value || categoryFilter.value || productCodeQuery.value || skuQuery.value || asinQuery.value) || activeConditions.value.length > 0);

function clearFilters() {
    brandFilter.value = null;
    categoryFilter.value = null;
    productCodeQuery.value = "";
    skuQuery.value = "";
    asinQuery.value = "";
    activeConditions.value = [];
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

function isGroupZero(stock: StockQuantities, group: "Amazon" | "BOSS"): boolean {
    return group === "Amazon" ? stock.amazonOwn === 0 && stock.amazonFba === 0 : stock.bossOwn === 0 && stock.bossRfc === 0;
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

    <div v-else class="flex flex-col gap-6">
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

        <BaseCard>
            <div class="flex flex-col gap-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <BaseSelect v-model="brandFilter" :options="brandOptions" placeholder="すべてのブランド" />
                    <BaseSelect v-model="categoryFilter" :options="categoryOptions" placeholder="すべてのカテゴリ" />
                    <BaseInput v-model="productCodeQuery" placeholder="品番で絞込" clearable clear-label="品番の絞込をクリア" />
                    <BaseInput v-model="skuQuery" placeholder="SKUで絞込" clearable clear-label="SKUの絞込をクリア" />
                    <BaseInput v-model="asinQuery" placeholder="ASINで絞込" clearable clear-label="ASINの絞込をクリア" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-medium text-slate-400">在庫ゼロ:</span>
                    <button
                        v-for="btn in ZERO_STOCK_BUTTONS"
                        :key="btn.key"
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
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                        :class="activeConditions.includes(btn.key) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
                        @click="toggleCondition(btn.key)"
                    >
                        {{ btn.label }}
                    </button>
                    <button v-if="hasActiveFilters" type="button" class="ml-auto text-xs font-medium text-primary-600 hover:underline" @click="clearFilters">条件をクリア</button>
                </div>

                <p class="text-xs text-slate-400"
                    >該当SKU: <span class="font-semibold text-slate-700">{{ matchedSkuCount }}</span> / 全{{ totalSkuCount }}件</p
                >
            </div>
        </BaseCard>

        <BaseCard v-if="filteredProducts.length === 0" :padded="false">
            <BaseEmpty icon="filter_alt_off" title="条件に一致するSKUがありません" description="0件です。絞込条件を見直してください。" />
        </BaseCard>

        <BaseCard v-for="product in filteredProducts" :key="product.productCode" :padded="false">
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
                <table class="w-full min-w-225 text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th rowspan="2" class="px-3 py-2 align-center w-40">SKU</th>
                            <th colspan="2" class="border-l border-b border-slate-200 px-3 py-1 text-center">Amazon</th>
                            <th colspan="2" class="border-l border-b border-slate-200 px-3 py-1 text-center">BOSS</th>
                            <th rowspan="2" class="border-l border-slate-200 px-3 py-2 w-20 text-center align-center">フリー在庫</th>
                            <th rowspan="2" class="border-l border-slate-200 px-3 py-2 w-20 text-center align-center">ECストック</th>
                            <th rowspan="2" class="border-l border-slate-200 px-3 py-2 align-center">メモ</th>
                        </tr>
                        <tr>
                            <th class="border-l border-slate-200 px-3 py-1 w-20 text-center font-normal normal-case">自社</th>
                            <th class="border-l border-slate-200 px-3 py-1 w-20 text-center font-normal normal-case">FBA</th>
                            <th class="border-l border-slate-200 px-3 py-1 w-20 text-center font-normal normal-case">自社</th>
                            <th class="border-l border-slate-200 px-3 py-1 w-20 text-center font-normal normal-case">RFC</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="sku in product.skus" :key="sku.skuCode" :class="sku.skuMatched ? 'bg-amber-50' : isZero(sku.stock) ? 'bg-slate-50/60' : ''">
                            <td class="px-3 py-2.5">
                                <div class="flex flex-col">
                                    <span class="font-semibold" :class="sku.skuMatched ? 'text-amber-800' : 'text-slate-900'">{{ sku.skuCode }}</span>
                                    <span class="flex items-center text-xs" :class="sku.skuMatched ? 'text-amber-700' : 'text-slate-400'">
                                        {{ sku.asin }}
                                    </span>
                                </div>
                            </td>
                            <td
                                v-for="field in STOCK_FIELDS"
                                :key="field.key"
                                class="border-l border-slate-200 px-3 py-2.5 text-right tabular-nums"
                                :class="[
                                    field.key === 'amazonOwn' || field.key === 'bossOwn' || field.key === 'freeStock' ? 'border-l border-slate-100' : '',
                                    (field.group === 'Amazon' || field.group === 'BOSS') && isGroupZero(sku.stock, field.group)
                                        ? 'bg-rose-50 font-semibold text-rose-400'
                                        : sku.stock[field.key] === 0
                                          ? 'text-slate-300'
                                          : 'font-semibold text-slate-900',
                                ]"
                            >
                                {{ sku.stock[field.key] }}
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
