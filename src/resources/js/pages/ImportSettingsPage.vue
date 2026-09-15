<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { fetchCrossWalkerProducts } from "@/api/crossWalker";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BasePagination from "@/components/ui/BasePagination.vue";
import { MAX_TARGET_PRODUCTS, useImportSettingsStore } from "@/stores/importSettings";
import { useUiStore } from "@/stores/ui";
import type { Product } from "@/types";

const store = useImportSettingsStore();
const toast = useUiStore();

const workingCodes = ref<string[]>([...store.selectedProductCodes]);
const initializedFromStore = ref(store.loaded);
const fetchedProducts = ref<Product[]>([]);
const draggedIndex = ref<number | null>(null);
const dragOverIndex = ref<number | null>(null);
const dirty = computed(() => JSON.stringify(workingCodes.value) !== JSON.stringify(store.selectedProductCodes));

const knownProducts = computed(() => {
    const products = new Map(store.products.map((product) => [product.productCode, product]));
    for (const product of fetchedProducts.value) products.set(product.productCode, product);
    return products;
});
const selectedProducts = computed(() => workingCodes.value.map((code) => knownProducts.value.get(code)).filter((product): product is Product => Boolean(product)));

watch(
    () => store.loaded,
    (loaded) => {
        if (loaded && !initializedFromStore.value) {
            workingCodes.value = [...store.selectedProductCodes];
            initializedFromStore.value = true;
        }
    },
);

const CANDIDATE_PER_PAGE = 20;

const search = ref("");
const appliedKeyword = ref("");
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
        const response = await fetchCrossWalkerProducts(appliedKeyword.value, page, CANDIDATE_PER_PAGE);

        if (requestId !== latestCandidateRequestId) return;
        candidateProducts.value = response.products;
        const products = new Map(fetchedProducts.value.map((product) => [product.productCode, product]));
        for (const product of response.products) products.set(product.productCode, product);
        fetchedProducts.value = [...products.values()];
        candidatePage.value = response.page;
        candidateTotal.value = response.total;
        candidateTotalPages.value = response.totalPages;
    } catch (error) {
        if (requestId !== latestCandidateRequestId) return;
        candidateProducts.value = [];
        candidateTotal.value = 0;
        candidateTotalPages.value = 1;
        candidateError.value = (axios.isAxiosError<{ message?: string }>(error) && error.response?.data.message) || "品番の取得に失敗しました。時間をおいて再度検索してください。";
    } finally {
        if (requestId === latestCandidateRequestId) candidateLoading.value = false;
    }
}

function searchCandidates() {
    appliedKeyword.value = search.value.trim();
    void loadCandidatePage(1);
}

function changeCandidatePage(page: number) {
    void loadCandidatePage(page);
}

onMounted(() => {
    void loadCandidatePage(1);
});

function add(product: Product) {
    if (workingCodes.value.length >= MAX_TARGET_PRODUCTS || workingCodes.value.includes(product.productCode)) return;
    workingCodes.value.push(product.productCode);
}

function remove(code: string) {
    workingCodes.value = workingCodes.value.filter((c) => c !== code);
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
}

const saving = ref(false);

async function save() {
    if (saving.value) return;
    if (workingCodes.value.length === 0) {
        toast.push("取込対象品番を1件以上選択してください。", "error");
        return;
    }
    saving.value = true;
    try {
        await store.save([...workingCodes.value]);
        toast.push("取込設定を保存しました");
    } catch (error) {
        const message = (axios.isAxiosError<{ message?: string }>(error) && error.response?.data.message) || "取込設定の保存に失敗しました。時間をおいて再度お試しください。";
        toast.push(message, "error");
    } finally {
        saving.value = false;
    }
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
                    <BaseButton variant="primary" :disabled="!dirty" :loading="saving" @click="save">この設定を保存</BaseButton>
                    <BaseButton variant="secondary" :disabled="!dirty || saving" @click="resetChanges">変更を取り消す</BaseButton>
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
                <form class="border-b border-slate-200 px-5 py-4" @submit.prevent="searchCandidates">
                    <div class="flex gap-2">
                        <BaseInput v-model="search" class="min-w-0 flex-1" size="sm" placeholder="品番・SKU・ASIN・TQ情報で検索" :disabled="candidateLoading" />
                        <BaseButton type="submit" variant="primary" size="sm" icon="search" :loading="candidateLoading" class="shrink-0">検索</BaseButton>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">CrossWalkerから有効な品番を検索します。</p>
                </form>

                <div v-if="candidateLoading" class="flex h-32 items-center justify-center gap-2 text-sm text-slate-500">
                    <AppIcon name="refresh" :size="18" class="animate-spin" />
                    <span>品番を取得中…</span>
                </div>
                <BaseEmpty v-else-if="candidateError" icon="error" title="品番を取得できませんでした" :description="candidateError" />
                <BaseEmpty v-else-if="candidateProducts.length === 0" icon="search_off" title="条件に一致する品番がありません" />
                <div v-else class="flex max-h-122.25 flex-col">
                    <ul class="min-h-0 divide-y divide-slate-100 overflow-y-auto">
                        <li v-for="product in candidateProducts" :key="product.productCode" class="flex items-center justify-between gap-3 px-5 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">
                                    {{ product.productCode }}
                                </p>
                                <p class="text-xs text-slate-400">{{ product.brand }} ／ {{ product.category }} ／ SKU {{ product.skus.length }}件</p>
                            </div>
                            <BaseButton
                                variant="secondary"
                                size="sm"
                                :icon="workingCodes.includes(product.productCode) ? 'check' : 'add'"
                                :disabled="workingCodes.includes(product.productCode) || workingCodes.length >= MAX_TARGET_PRODUCTS"
                                @click="add(product)"
                            >
                                {{ workingCodes.includes(product.productCode) ? "追加済み" : "追加" }}
                            </BaseButton>
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
