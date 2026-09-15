<script setup lang="ts">
import { computed, ref } from "vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import ConfirmModal from "@/components/ui/ConfirmModal.vue";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { daysSince, formatDateTime, formatSizeKb } from "@/utils/format";
import type { SurveyFile } from "@/types";

const surveysStore = useSurveysStore();
const toast = useUiStore();

const FILE_RETENTION_DAYS = 30;

const groups = computed(() =>
    surveysStore.sortedSurveys.map((survey) => ({
        survey,
        files: survey.files.filter((f) => daysSince(f.uploadedAt) <= FILE_RETENTION_DAYS),
    })),
);

function downloadFile(file: SurveyFile) {
    window.location.assign(`/api/survey-files/${file.id}/download`);
}

function downloadZip(surveyId: string) {
    window.location.assign(`/api/surveys/${surveyId}/files/download`);
}

const pendingSingleDelete = ref<{ surveyId: string; file: SurveyFile } | null>(null);
const pendingBulkDelete = ref<{ surveyId: string; count: number } | null>(null);

function requestDeleteFile(surveyId: string, file: SurveyFile) {
    pendingSingleDelete.value = { surveyId, file };
}

async function confirmDeleteFile() {
    if (!pendingSingleDelete.value) return;
    const { surveyId, file } = pendingSingleDelete.value;
    await surveysStore.removeFile(surveyId, file.id);
    toast.push("ファイルを削除しました");
    pendingSingleDelete.value = null;
}

function requestBulkDelete(surveyId: string, count: number) {
    pendingBulkDelete.value = { surveyId, count };
}

async function confirmBulkDelete() {
    if (!pendingBulkDelete.value) return;
    const { surveyId } = pendingBulkDelete.value;
    await surveysStore.removeFiles(surveyId);
    toast.push("ファイルを一括削除しました");
    pendingBulkDelete.value = null;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">ファイル管理</h1>
            <p class="mt-1 text-sm text-slate-500">
                調査ごとに取込ファイルを一覧表示します。アップロードから{{ FILE_RETENTION_DAYS }}日を経過したファイルは自動的に削除されます（調査結果・メモは残ります）。
            </p>
        </div>

        <BaseCard v-if="groups.length === 0" :padded="false">
            <BaseEmpty icon="folder_off" title="調査履歴がありません" />
        </BaseCard>

        <BaseCard v-for="group in groups" :key="group.survey.id" :title="formatDateTime(group.survey.executedAt)" :padded="false">
            <template #actions>
                <RouterLink :to="{ name: 'survey-result', params: { id: group.survey.id } }" class="text-xs text-primary-600 hover:underline">調査結果を見る</RouterLink>
                <template v-if="group.files.length > 0">
                    <BaseButton variant="secondary" size="sm" icon="folder_zip" @click="downloadZip(group.survey.id)">一括ダウンロード</BaseButton>
                    <BaseButton variant="danger-ghost" size="sm" icon="delete_sweep" @click="requestBulkDelete(group.survey.id, group.files.length)">一括削除</BaseButton>
                </template>
            </template>

            <p v-if="group.files.length === 0" class="px-5 py-6 text-sm text-slate-400">
                取込ファイルはありません（保存期間の経過、または削除済み）。調査結果の閲覧・メモ編集・Excel出力は引き続き可能です。
            </p>

            <ul v-else class="divide-y divide-slate-100">
                <li v-for="file in group.files" :key="file.id" class="flex items-center justify-between gap-3 px-5 py-2.5">
                    <div class="flex min-w-0 items-center gap-3">
                        <BaseBadge class="shrink-0">{{ file.type }}</BaseBadge>
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-800">{{ file.fileName }}</p>
                            <p class="text-xs text-slate-400">{{ formatDateTime(file.uploadedAt) }} ／ {{ formatSizeKb(file.sizeKb) }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <BaseButton variant="ghost" size="sm" icon="download" aria-label="ダウンロード" @click="downloadFile(file)" />
                        <BaseButton variant="ghost" size="sm" icon="delete" aria-label="削除" @click="requestDeleteFile(group.survey.id, file)" />
                    </div>
                </li>
            </ul>
        </BaseCard>

        <ConfirmModal
            :open="pendingSingleDelete !== null"
            title="ファイルの削除"
            :message="`「${pendingSingleDelete?.file.fileName}」を削除します。調査結果とメモは残りますが、ファイルは元に戻せません。`"
            @cancel="pendingSingleDelete = null"
            @confirm="confirmDeleteFile"
        />
        <ConfirmModal
            :open="pendingBulkDelete !== null"
            title="ファイルの一括削除"
            :message="`この調査に紐づく取込ファイル ${pendingBulkDelete?.count}件をすべて削除します。調査結果とメモは残ります。`"
            @cancel="pendingBulkDelete = null"
            @confirm="confirmBulkDelete"
        />
    </div>
</template>
