<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { fetchCrossWalkerProducts } from "@/api/crossWalker";
import { useRouter } from "vue-router";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import StatusLamp from "@/components/ui/StatusLamp.vue";
import BasePagination from "@/components/ui/BasePagination.vue";
import { MAX_TARGET_PRODUCTS, useImportSettingsStore } from "@/stores/importSettings";
import { useUiStore } from "@/stores/ui";
import type { Product } from "@/types";

const store = useImportSettingsStore();
const props = defineProps<{ id?: string }>();
const router = useRouter();

const toast = useUiStore();

const workingSettingId = ref<string | null>(null);
const settingName = ref("");
const savedName = ref(settingName.value);
const workingCodes = ref<string[]>([]);
const fetchedProducts = ref<Product[]>([]);
const draggedIndex = ref<number | null>(null);
const dragOverIndex = ref<number | null>(null);
const isNew = computed(() => workingSettingId.value === null);
const dirty = computed(() => settingName.value.trim() !== savedName.value || JSON.stringify(workingCodes.value) !== JSON.stringify(store.selectedSetting?.productCodes ?? []));

const knownProducts = computed(() => {
    const products = new Map(store.settings.flatMap((setting) => setting.products).map((product) => [product.productCode, product]));
    for (const product of fetchedProducts.value) products.set(product.productCode, product);
    return products;
});
const selectedProducts = computed(() => workingCodes.value.map((code) => knownProducts.value.get(code)).filter((product): product is Product => Boolean(product)));

function loadSetting(settingId: string | null) {
    store.select(settingId);
    workingSettingId.value = settingId;
    settingName.value = store.selectedSetting?.name ?? "";
    savedName.value = settingName.value;
    workingCodes.value = [...store.selectedProductCodes];
}

watch(
    [() => store.loaded, () => props.id],
    ([loaded, settingId]) => {
        if (!loaded) return;
        if (settingId === undefined) {
            loadSetting(null);
            return;
        }
        if (store.settings.some((setting) => setting.id === settingId)) {
            loadSetting(settingId);
            return;
        }
        void router.replace({ name: "import-settings" });
    },
    { immediate: true },
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
    if (isNew.value) loadSetting(null);
    else loadSetting(workingSettingId.value);
}

const saving = ref(false);

async function save() {
    if (saving.value) return;
    const name = settingName.value.trim();
    if (name === "") {
        toast.push("グループ名を入力してください。", "error");
        return;
    }
    if (workingCodes.value.length === 0) {
        toast.push("取込対象品番を1件以上選択してください。", "error");
        return;
    }
    saving.value = true;
    try {
        if (isNew.value) await store.create(name, [...workingCodes.value]);
        else await store.update(workingSettingId.value as string, name, [...workingCodes.value]);
        toast.push("品番グループを保存しました");
        await router.push({ name: "import-settings" });
    } catch (error) {
        const message = (axios.isAxiosError<{ message?: string }>(error) && error.response?.data.message) || "品番グループの保存に失敗しました。時間をおいて再度お試しください。";
        toast.push(message, "error");
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <BaseButton variant="ghost" size="sm" icon="chevron_left" class="-ml-2 w-fit" @click="router.push({ name: 'import-settings' })">一覧に戻る</BaseButton>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div>
                <h1 class="text-[22px] leading-tight font-semibold">{{ isNew ? "品番グループを新規作成" : "品番グループを編集" }}</h1>
                <p class="mt-1 text-[12.5px] text-ink-2">用途ごとに名前を付けて、表示する品番と並び順を設定します。登録した品番はすべて取込対象になります。</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 lg:ml-auto">
                <StatusLamp v-if="dirty" state="warn" label="未保存の変更があります" class="mr-1 text-[11.5px] text-ink-2" data-dirty-indicator />
                <BaseButton variant="secondary" :disabled="!dirty || saving" @click="resetChanges">変更を取り消す</BaseButton>
                <BaseButton variant="primary" :disabled="!dirty" :loading="saving" @click="save">このグループを保存</BaseButton>
            </div>
        </div>

        <BaseCard title="基本情報">
            <BaseInput v-model="settingName" class="max-w-xl" label="グループ名" placeholder="例: 売上TOP20" required :disabled="saving" />
        </BaseCard>

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
            <BaseCard title="取込対象品番" description="ドラッグして表示順を並び替えられます。" :padded="false" class="lg:order-2">
                <template #actions>
                    <span class="flex items-center gap-2 font-mono text-[11px] text-ink-2" data-target-capacity>
                        <span class="inline-block h-[5px] w-[110px] overflow-hidden rounded-xs bg-ink/8" aria-hidden="true"
                            ><span class="block h-full rounded-xs bg-ink" :style="{ width: `${Math.min(100, (workingCodes.length / MAX_TARGET_PRODUCTS) * 100)}%` }"></span></span
                        >{{ workingCodes.length }} / {{ MAX_TARGET_PRODUCTS }}
                    </span>
                </template>

                <BaseEmpty v-if="selectedProducts.length === 0" title="対象品番が設定されていません" description="品番追加から追加してください。" />

                <ul v-else class="max-h-144 divide-y divide-line-2 overflow-y-auto">
                    <li
                        v-for="(product, index) in selectedProducts"
                        :key="product.productCode"
                        draggable="true"
                        class="flex items-center justify-between gap-3 px-[18px] py-2 transition-colors"
                        :class="{
                            'bg-surface-2 ring-2 ring-ink/25 ring-inset': dragOverIndex === index && draggedIndex !== index,
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
                            <AppIcon name="drag_indicator" :size="18" class="text-faint" />
                            <span class="w-5 shrink-0 text-center font-mono text-[10.5px] text-muted">{{ `${index + 1}`.padStart(2, "0") }}</span>
                            <div class="min-w-0 flex flex-start items-center gap-2">
                                <p class="truncate font-mono text-[13px] font-semibold text-ink">
                                    {{ product.productCode }}
                                    <span class="ml-1 font-sans text-[11px] font-normal text-muted">SKU {{ product.skus.length }}件</span>
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
                <form class="border-b border-line-2 px-[18px] py-3.5" @submit.prevent="searchCandidates">
                    <div class="flex gap-2">
                        <BaseInput v-model="search" class="min-w-0 flex-1 [&_input]:font-mono" placeholder="品番・SKU・ASIN・TQ情報で検索" :disabled="candidateLoading" />
                        <BaseButton type="submit" variant="secondary" icon="search" :loading="candidateLoading" class="shrink-0">検索</BaseButton>
                    </div>
                    <p class="mt-2 text-[11px] text-muted">CrossWalker から有効な品番を検索します。</p>
                </form>

                <div v-if="candidateLoading" class="flex h-32 items-center justify-center gap-2 text-[12.5px] text-muted">
                    <AppIcon name="refresh" :size="18" class="animate-spin" />
                    <span>品番を取得中…</span>
                </div>
                <BaseEmpty v-else-if="candidateError" icon="error" title="品番を取得できませんでした" :description="candidateError" />
                <BaseEmpty v-else-if="candidateProducts.length === 0" icon="search_off" title="条件に一致する品番がありません" />
                <div v-else class="flex max-h-122.25 flex-col">
                    <ul class="min-h-0 divide-y divide-line-2 overflow-y-auto">
                        <li
                            v-for="product in candidateProducts"
                            :key="product.productCode"
                            data-testid="candidate-product-row"
                            :data-added="workingCodes.includes(product.productCode) ? 'true' : undefined"
                            class="flex items-center justify-between gap-3 px-[18px] py-2 transition-colors"
                            :class="workingCodes.includes(product.productCode) ? 'bg-surface-2' : 'bg-surface'"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-mono text-[13px] font-semibold" :class="workingCodes.includes(product.productCode) ? 'text-muted' : 'text-ink'">
                                    {{ product.productCode }}
                                </p>
                                <p class="text-[11px]" :class="workingCodes.includes(product.productCode) ? 'text-faint' : 'text-muted'">
                                    {{ product.brand }} ／ {{ product.category }} ／ SKU {{ product.skus.length }}件
                                </p>
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
