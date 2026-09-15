<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BasePagination from "@/components/ui/BasePagination.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import { BRAND_LIST, CATEGORY_LIST, fetchProductPage, findProduct } from "@/constants/masterData";
import { MAX_TARGET_PRODUCTS, useImportSettingsStore } from "@/stores/importSettings";
import { useUiStore } from "@/stores/ui";
import type { Product, SelectOption } from "@/types";

const store = useImportSettingsStore();
const toast = useUiStore();

const workingCodes = ref<string[]>([...store.selectedProductCodes]);
const draggedIndex = ref<number | null>(null);
const dragOverIndex = ref<number | null>(null);
const dirty = computed(() => JSON.stringify(workingCodes.value) !== JSON.stringify(store.selectedProductCodes));

const selectedProducts = computed(() => workingCodes.value.map((code) => findProduct(code)).filter((p): p is NonNullable<typeof p> => Boolean(p)));

const CANDIDATE_PER_PAGE = 20;

const search = ref("");
const brandFilter = ref<string | null>(null);
const categoryFilter = ref<string | null>(null);
const brandOptions = computed<SelectOption[]>(() => BRAND_LIST.map((b) => ({ value: b, label: b })));
const categoryOptions = computed<SelectOption[]>(() => CATEGORY_LIST.map((c) => ({ value: c, label: c })));

const appliedFilters = ref({
    query: "",
    brand: null as string | null,
    category: null as string | null,
});
const candidateProducts = ref<Product[]>([]);
const candidatePage = ref(1);
const candidateTotal = ref(0);
const candidateTotalPages = ref(1);
const candidateLoading = ref(false);
const candidateError = ref("");
let latestCandidateRequestId = 0;

async function loadCandidatePage(page: number) {
    const requestId = ++latestCandidateRequestId;
    candidateLoading.value = true;
    candidateError.value = "";

    try {
        const response = await fetchProductPage({
            query: appliedFilters.value.query,
            brand: appliedFilters.value.brand,
            category: appliedFilters.value.category,
            page,
            perPage: CANDIDATE_PER_PAGE,
            excludedProductCodes: workingCodes.value,
        });

        if (requestId !== latestCandidateRequestId) return;
        candidateProducts.value = response.products;
        candidatePage.value = response.page;
        candidateTotal.value = response.total;
        candidateTotalPages.value = response.totalPages;
    } catch {
        if (requestId !== latestCandidateRequestId) return;
        candidateProducts.value = [];
        candidateTotal.value = 0;
        candidateTotalPages.value = 1;
        candidateError.value = "品番の取得に失敗しました。時間をおいて再度検索してください。";
    } finally {
        if (requestId === latestCandidateRequestId) candidateLoading.value = false;
    }
}

function searchCandidates() {
    appliedFilters.value = {
        query: search.value.trim(),
        brand: brandFilter.value,
        category: categoryFilter.value,
    };
    void loadCandidatePage(1);
}

function changeCandidatePage(page: number) {
    void loadCandidatePage(page);
}

onMounted(() => {
    void loadCandidatePage(1);
});

function add(code: string) {
    if (workingCodes.value.length >= MAX_TARGET_PRODUCTS || workingCodes.value.includes(code)) return;
    workingCodes.value.push(code);
    void loadCandidatePage(candidatePage.value);
}

function remove(code: string) {
    workingCodes.value = workingCodes.value.filter((c) => c !== code);
    void loadCandidatePage(candidatePage.value);
}

function startDragging(event: DragEvent, index: number) {
    draggedIndex.value = index;
    dragOverIndex.value = index;

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", workingCodes.value[index] ?? "");
    }
}

function dragOver(index: number) {
    if (draggedIndex.value === null) return;
    dragOverIndex.value = index;
}

function dropAt(index: number) {
    const fromIndex = draggedIndex.value;
    if (fromIndex === null || fromIndex === index) {
        stopDragging();
        return;
    }

    const [movedCode] = workingCodes.value.splice(fromIndex, 1);
    if (movedCode) workingCodes.value.splice(index, 0, movedCode);
    stopDragging();
}

function stopDragging() {
    draggedIndex.value = null;
    dragOverIndex.value = null;
}

function resetChanges() {
    workingCodes.value = [...store.selectedProductCodes];
    void loadCandidatePage(candidatePage.value);
}

function save() {
    if (workingCodes.value.length === 0) {
        toast.push("取込対象品番を1件以上選択してください。", "error");
        return;
    }
    store.$patch({ selectedProductCodes: [...workingCodes.value] });
    store.resyncFromApi();
    toast.push("取込設定を保存しました");
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">取込設定</h1>
                <p class="mt-1 text-sm text-slate-500">取込対象の品番を選択・削除し、並び順を設定します。</p>
            </div>
            <div class="flex shrink-0 flex-col gap-2 sm:items-end">
                <div class="flex flex-wrap gap-2">
                    <BaseButton variant="primary" :disabled="!dirty" @click="save">この設定を保存</BaseButton>
                    <BaseButton variant="secondary" :disabled="!dirty" @click="resetChanges">変更を取り消す</BaseButton>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span v-if="dirty" class="text-xs font-medium text-amber-600">未保存の変更があります</span>
                </div>
            </div>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            <BaseCard title="取込済み品番" description="ドラッグして表示順を並び替えられます。" :padded="false" class="lg:order-2">
                <template #actions>
                    <span class="text-xs font-medium text-slate-500">{{ workingCodes.length }} / {{ MAX_TARGET_PRODUCTS }}</span>
                </template>

                <BaseEmpty v-if="selectedProducts.length === 0" title="対象品番が設定されていません" description="品番追加から追加してください。" />

                <ul v-else class="max-h-144 divide-y divide-slate-100 overflow-y-auto">
                    <li
                        v-for="(product, index) in selectedProducts"
                        :key="product.productCode"
                        draggable="true"
                        class="flex items-center justify-between gap-3 px-4 py-3 transition-colors"
                        :class="{
                            'bg-primary-50 ring-2 ring-primary-300 ring-inset': dragOverIndex === index && draggedIndex !== index,
                            'cursor-grabbing': draggedIndex !== null,
                            'cursor-grab': draggedIndex === null,
                            'opacity-50': draggedIndex === index,
                        }"
                        @dragstart="startDragging($event, index)"
                        @dragover.prevent="dragOver(index)"
                        @drop.prevent="dropAt(index)"
                        @dragend="stopDragging"
                    >
                        <div class="flex min-w-0 items-center gap-2.5">
                            <AppIcon name="drag_indicator" :size="20" class="text-slate-400" />
                            <span class="w-5 shrink-0 text-center text-xs tabular-nums text-slate-400">{{ index + 1 }}</span>
                            <div class="min-w-0 flex flex-start items-center gap-2">
                                <p class="truncate text-sm font-medium text-slate-900">
                                    {{ product.productCode }}
                                    <span class="text-xs text-slate-400">SKU {{ product.skus.length }}件</span>
                                </p>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    <BaseBadge>{{ product.brand }}</BaseBadge>
                                    <BaseBadge>{{ product.category }}</BaseBadge>
                                </div>
                            </div>
                        </div>
                        <BaseButton variant="danger-ghost" size="sm" icon="delete" aria-label="対象から削除" @click="remove(product.productCode)" />
                    </li>
                </ul>
            </BaseCard>

            <BaseCard title="品番を追加" :padded="false" class="lg:order-1">
                <form class="grid grid-cols-2 gap-2 border-b border-slate-200 px-5 py-4" @submit.prevent="searchCandidates">
                    <div class="col-span-2 flex gap-2">
                        <BaseInput v-model="search" class="min-w-0 flex-1" size="sm" placeholder="品番で検索" :disabled="candidateLoading" />
                        <BaseButton type="submit" variant="primary" size="sm" icon="search" :loading="candidateLoading" class="shrink-0">検索</BaseButton>
                    </div>
                    <BaseSelect v-model="brandFilter" size="sm" :options="brandOptions" placeholder="すべてのブランド" :disabled="candidateLoading" />
                    <BaseSelect v-model="categoryFilter" size="sm" :options="categoryOptions" placeholder="すべてのカテゴリ" :disabled="candidateLoading" />
                </form>

                <div v-if="candidateLoading" class="flex h-32 items-center justify-center gap-2 text-sm text-slate-500">
                    <AppIcon name="refresh" :size="18" class="animate-spin" />
                    <span>品番を取得中…</span>
                </div>
                <BaseEmpty v-else-if="candidateError" icon="error" title="品番を取得できませんでした" :description="candidateError" />
                <BaseEmpty v-else-if="candidateProducts.length === 0" icon="search_off" title="条件に一致する追加可能な品番がありません" />
                <div v-else class="flex max-h-122.25 flex-col">
                    <ul class="min-h-0 divide-y divide-slate-100 overflow-y-auto">
                        <li v-for="product in candidateProducts" :key="product.productCode" class="flex items-center justify-between gap-3 px-5 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">
                                    {{ product.productCode }}
                                </p>
                                <p class="text-xs text-slate-400">{{ product.brand }} ／ {{ product.category }} ／ SKU {{ product.skus.length }}件</p>
                            </div>
                            <BaseButton variant="secondary" size="sm" icon="add" :disabled="workingCodes.length >= MAX_TARGET_PRODUCTS" @click="add(product.productCode)">追加</BaseButton>
                        </li>
                    </ul>
                    <BasePagination
                        v-if="candidateTotal > CANDIDATE_PER_PAGE"
                        class="shrink-0"
                        :page="candidatePage"
                        :total-pages="candidateTotalPages"
                        :total="candidateTotal"
                        :per-page="CANDIDATE_PER_PAGE"
                        @change="changeCandidatePage"
                    />
                </div>
            </BaseCard>
        </div>
    </div>
</template>
