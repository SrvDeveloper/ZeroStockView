<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRouter } from "vue-router";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BasePagination from "@/components/ui/BasePagination.vue";
import ConfirmModal from "@/components/ui/ConfirmModal.vue";
import { MAX_SURVEY_HISTORY, useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { formatDateTime } from "@/utils/format";

const router = useRouter();
const surveys = useSurveysStore();
const toast = useUiStore();

const PER_PAGE = 10;
const page = ref(1);

const list = computed(() => surveys.sortedSurveys);
const latestId = computed(() => surveys.latestSurvey?.id);
const totalPages = computed(() => Math.max(1, Math.ceil(list.value.length / PER_PAGE)));
const pagedList = computed(() => list.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));

watch(totalPages, (tp) => {
    if (page.value > tp) page.value = tp;
});

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
                調査結果を新しい順に一覧表示します（最大{{ MAX_SURVEY_HISTORY }}件保持、{{ MAX_SURVEY_HISTORY + 1 }}件目以降は自動的に削除されます）。全{{ list.length }}件。
            </p>
        </div>

        <BaseCard v-if="list.length === 0" :padded="false">
            <BaseEmpty icon="history" title="調査履歴がありません" />
        </BaseCard>

        <BaseCard v-else :padded="false">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-2.5">調査日時</th>
                            <th class="px-5 py-2.5">取込設定</th>
                            <th class="px-5 py-2.5">対象品番数</th>
                            <th class="px-5 py-2.5">SKU数</th>
                            <th class="px-5 py-2.5">取込ファイル数</th>
                            <th class="px-5 py-2.5"></th>
                            <th class="w-44 px-5 py-2.5 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="survey in pagedList" :key="survey.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3 font-medium text-slate-900">{{ formatDateTime(survey.executedAt) }}</td>
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

        <ConfirmModal
            :open="pendingDeleteId !== null"
            title="調査履歴の削除"
            :message="`${pendingDeleteLabel} の調査結果を削除します。関連するメモと取込ファイルも削除され、元に戻せません。`"
            @cancel="pendingDeleteId = null"
            @confirm="confirmDelete"
        />
    </div>
</template>
