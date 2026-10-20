<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import SurveyFilesZipButton from "@/components/survey/SurveyFilesZipButton.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import StatusLamp from "@/components/ui/StatusLamp.vue";
import { IMPORT_FILE_TYPES, type SurveyBase, type SurveyFile } from "@/types";
import { downloadFromApi } from "@/utils/download";
import { fileRemainingDays } from "@/utils/files";

const props = defineProps<{ survey: SurveyBase }>();

const FILE_LABELS: Record<string, string> = { 倉庫毎の在庫数レポート: "BOSS", KEEP一覧表: "ECストック", 在庫一覧照会表: "フリー在庫" };
const knownTypes = new Set<string>(IMPORT_FILE_TYPES.map(({ type }) => type));
const missingIds = ref<Set<string>>(new Set());
const downloading = ref<string | null>(null);
const errorMessage = ref("");

watch([() => props.survey.id, () => props.survey.files.map((file) => file.id).join("|")], () => {
    missingIds.value = new Set();
    errorMessage.value = "";
});

const entries = computed(() => [
    ...IMPORT_FILE_TYPES.map(({ type }) => ({ key: type, label: FILE_LABELS[type] ?? type, file: props.survey.files.find((file) => file.type === type) ?? null })),
    ...props.survey.files.filter((file) => !knownTypes.has(file.type)).map((file) => ({ key: file.id, label: file.type, file })),
]);

function isAvailable(file: SurveyFile | null): file is SurveyFile {
    return file !== null && fileRemainingDays(file.uploadedAt) > 0 && !missingIds.value.has(file.id);
}

/** 狭い詳細欄に収まるよう、ダウンロードできないときは短い理由だけを出す（ファイル名はツールチップ） */
function fileStatus(file: SurveyFile | null): string {
    if (!file) return "ファイルなし";
    if (missingIds.value.has(file.id)) return "見つかりません";
    if (!(fileRemainingDays(file.uploadedAt) > 0)) return "保存期限切れ";
    return file.fileName;
}

async function downloadFile(file: SurveyFile): Promise<void> {
    if (!isAvailable(file) || downloading.value !== null) return;
    downloading.value = file.id;
    errorMessage.value = "";
    try {
        await downloadFromApi(`/api/survey-files/${file.id}/download`, file.fileName);
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
            missingIds.value = new Set([...missingIds.value, file.id]);
            errorMessage.value = "ファイルが見つかりません。保存期限が過ぎたか、削除された可能性があります。";
        } else {
            errorMessage.value = "ファイルをダウンロードできませんでした。時間をおいて再度お試しください。";
        }
    } finally {
        downloading.value = null;
    }
}
</script>

<template>
    <section class="flex flex-col gap-1" aria-label="取込ファイル" data-survey-file-downloads>
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-[11px] font-medium text-muted">取込ファイル</h3>
            <SurveyFilesZipButton :survey="survey" />
        </div>
        <div v-for="entry in entries" :key="entry.key" class="flex h-[26px] min-w-0 items-center gap-2.5" :data-file-entry="entry.key">
            <StatusLamp :state="isAvailable(entry.file) ? 'ok' : 'warn'" :label="entry.label" class="w-[92px] shrink-0" />
            <span class="min-w-0 flex-1 truncate font-mono text-[10.5px]" :class="isAvailable(entry.file) ? 'text-ink-2' : 'text-muted'" :title="entry.file?.fileName">{{
                fileStatus(entry.file)
            }}</span>
            <BaseButton
                v-if="entry.file"
                variant="ghost"
                size="sm"
                icon="download"
                class="w-[26px] shrink-0 px-0!"
                :aria-label="`${entry.file.fileName}をダウンロード`"
                :title="isAvailable(entry.file) ? 'ダウンロード' : undefined"
                :disabled="!isAvailable(entry.file) || downloading !== null"
                :loading="downloading === entry.file.id"
                @click="downloadFile(entry.file)"
            />
            <span v-else class="w-[26px] shrink-0" aria-hidden="true"></span>
        </div>
        <p v-if="errorMessage" class="text-[11px] leading-relaxed text-danger" role="alert">{{ errorMessage }}</p>
    </section>
</template>
