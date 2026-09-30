<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRouter } from "vue-router";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BasePagination from "@/components/ui/BasePagination.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import ConfirmModal from "@/components/ui/ConfirmModal.vue";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import type { SelectOption } from "@/types";
import { formatDateTime } from "@/utils/format";

const router = useRouter();
const surveys = useSurveysStore();
const toast = useUiStore();

const PER_PAGE = 10;
const page = ref(1);
const startDate = ref("");
const endDate = ref("");
const importSettingFilter = ref("");

const NO_IMPORT_SETTING = "__no_import_setting__";
const allList = computed(() => surveys.sortedSurveys);
const importSettingOptions = computed<SelectOption[]>(() => {
    const names = new Set(allList.value.map((survey) => survey.importSettingName).filter((name): name is string => Boolean(name)));
    const options: SelectOption[] = [...names].sort((a, b) => a.localeCompare(b, "ja")).map((name) => ({ value: name, label: name }));

    if (allList.value.some((survey) => !survey.importSettingName)) {
        options.push({ value: NO_IMPORT_SETTING, label: "記録なし" });
    }

    return options;
});

function localDateKey(iso: string): string {
    const date = new Date(iso);
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, "0");
    const day = `${date.getDate()}`.padStart(2, "0");

    return `${year}-${month}-${day}`;
}

const invalidDateRange = computed(() => startDate.value !== "" && endDate.value !== "" && startDate.value > endDate.value);
const list = computed(() => {
    if (invalidDateRange.value) return [];

    return allList.value.filter((survey) => {
        const surveyDate = localDateKey(survey.executedAt);
        if (startDate.value && surveyDate < startDate.value) return false;
        if (endDate.value && surveyDate > endDate.value) return false;
        if (importSettingFilter.value === NO_IMPORT_SETTING && survey.importSettingName) return false;
        if (importSettingFilter.value && importSettingFilter.value !== NO_IMPORT_SETTING && survey.importSettingName !== importSettingFilter.value) return false;

        return true;
    });
});
const hasActiveFilters = computed(() => importSettingFilter.value !== "" || startDate.value !== "" || endDate.value !== "");
const dateInputClass = computed(() =>
    invalidDateRange.value ? "border-rose-400 focus:border-rose-500 focus:outline-rose-500/40" : "border-slate-300 focus:border-primary-500 focus:outline-primary-500/40",
);
const latestId = computed(() => surveys.latestSurvey?.id);
const totalPages = computed(() => Math.max(1, Math.ceil(list.value.length / PER_PAGE)));
const pagedList = computed(() => list.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));

watch([startDate, endDate, importSettingFilter], () => {
    page.value = 1;
});

watch(totalPages, (tp) => {
    if (page.value > tp) page.value = tp;
});

function updateImportSettingFilter(value: string | number | null) {
    importSettingFilter.value = value === null ? "" : String(value);
}

function resetFilters() {
    startDate.value = "";
    endDate.value = "";
    importSettingFilter.value = "";
}

function skuCount(id: string): number {
    return surveys.getSurvey(id)?.products.reduce((sum, p) => sum + p.skus.length, 0) ?? 0;
}

const pendingDeleteId = ref<string | null>(null);
const pendingDeleteLabel = computed(() => {
    const survey = list.value.find((s) => s.id === pendingDeleteId.value);
    return survey ? formatDateTime(survey.executedAt) : "";
});

function requestDelete(id: string) {
    pendingDeleteId.value = id;
}

async function confirmDelete() {
    if (!pendingDeleteId.value) return;
    await surveys.removeSurvey(pendingDeleteId.value);
    toast.push("調査履歴を削除しました");
    pendingDeleteId.value = null;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">在庫調査履歴</h1>
            <p class="mt-1 text-sm text-slate-500">
                調査結果を新しい順に一覧表示します。履歴は削除するまで保持されます。全{{ allList.length }}件<span v-if="hasActiveFilters">中{{ list.length }}件を表示</span>。
            </p>
        </div>

        <BaseCard v-if="allList.length === 0" :padded="false">
            <BaseEmpty icon="history" title="調査履歴がありません" />
        </BaseCard>

        <template v-else>
            <BaseCard title="絞込条件">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                    <div class="w-full lg:w-auto">
                        <span class="mb-1 block text-xs font-medium text-slate-600">調査期間</span>
                        <div class="flex items-center gap-2">
                            <input
                                v-model="startDate"
                                data-testid="survey-date-from"
                                type="date"
                                :max="endDate || undefined"
                                aria-label="調査期間の開始日"
                                class="h-10 min-w-0 flex-1 rounded-lg border bg-white px-3 text-sm text-slate-700 focus:outline-2 lg:w-40"
                                :class="dateInputClass"
                            />
                            <span class="text-sm text-slate-400">〜</span>
                            <input
                                v-model="endDate"
                                data-testid="survey-date-to"
                                type="date"
                                :min="startDate || undefined"
                                aria-label="調査期間の終了日"
                                class="h-10 min-w-0 flex-1 rounded-lg border bg-white px-3 text-sm text-slate-700 focus:outline-2 lg:w-40"
                                :class="dateInputClass"
                            />
                        </div>
                        <p v-if="invalidDateRange" class="mt-1 text-xs text-rose-600">開始日は終了日以前の日付を指定してください。</p>
                    </div>

                    <div class="w-full lg:w-56">
                        <BaseSelect
                            :model-value="importSettingFilter"
                            data-testid="import-setting-filter"
                            :options="importSettingOptions"
                            label="取込設定"
                            placeholder="すべての取込設定"
                            @update:model-value="updateImportSettingFilter"
                        />
                    </div>

                    <BaseButton data-testid="reset-filters" variant="ghost" icon="filter_alt_off" :disabled="!hasActiveFilters" @click="resetFilters"> クリア </BaseButton>
                </div>
            </BaseCard>

            <BaseCard :padded="false">
                <BaseEmpty v-if="list.length === 0" icon="filter_alt_off" title="条件に一致する調査履歴がありません" description="絞込条件を変更するか、クリアしてください。">
                    <BaseButton variant="secondary" @click="resetFilters">絞込条件をクリア</BaseButton>
                </BaseEmpty>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-5 py-2.5">在庫基準日時</th>
                                <th class="px-5 py-2.5">取込設定</th>
                                <th class="px-5 py-2.5">対象品番数</th>
                                <th class="px-5 py-2.5">SKU数</th>
                                <th class="px-5 py-2.5">取込ファイル数</th>
                                <th class="px-5 py-2.5"></th>
                                <th class="w-44 px-5 py-2.5 text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="survey in pagedList" :key="survey.id" :data-survey-id="survey.id" class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <span class="block font-medium text-slate-900">{{ formatDateTime(survey.executedAt) }}</span>
                                    <span v-if="survey.createdAt" class="mt-0.5 block text-xs text-slate-400">結果作成: {{ formatDateTime(survey.createdAt) }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ survey.importSettingName || "記録なし" }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ survey.products.length }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ skuCount(survey.id) }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ survey.files.length }}</td>
                                <td class="px-5 py-3">
                                    <BaseBadge v-if="survey.id === latestId" tone="brand">最新</BaseBadge>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <BaseButton variant="secondary" size="sm" @click="router.push({ name: 'survey-result', params: { id: survey.id } })">結果を開く</BaseButton>
                                        <BaseButton variant="danger-ghost" size="sm" icon="delete" aria-label="削除" @click="requestDelete(survey.id)" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <BasePagination v-if="list.length > PER_PAGE" :page="page" :total-pages="totalPages" :total="list.length" :per-page="PER_PAGE" @change="page = $event" />
            </BaseCard>
        </template>

        <ConfirmModal
            :open="pendingDeleteId !== null"
            title="調査履歴の削除"
            :message="`${pendingDeleteLabel} の調査結果を削除します。関連するメモと取込ファイルも削除され、元に戻せません。`"
            @cancel="pendingDeleteId = null"
            @confirm="confirmDelete"
        />
    </div>
</template>
