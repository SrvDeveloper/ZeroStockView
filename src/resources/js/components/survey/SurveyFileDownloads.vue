<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import StatusLamp from "@/components/ui/StatusLamp.vue";
import { IMPORT_FILE_TYPES, type Survey, type SurveyFile } from "@/types";
import { fileRemainingDays } from "@/utils/files";
import { formatDateTime } from "@/utils/format";

const props = defineProps<{ survey: Survey }>();

const FILE_LABELS: Record<string, string> = { 倉庫毎の在庫数レポート: "BOSS", KEEP一覧表: "ECストック", 在庫一覧照会表: "フリー在庫" };
const knownTypes = new Set<string>(IMPORT_FILE_TYPES.map(({ type }) => type));
const missingIds = ref<Set<string>>(new Set());
const zipUnavailable = ref(false);
const downloading = ref<string | null>(null);
const errorMessage = ref("");

watch([() => props.survey.id, () => props.survey.files.map((file) => file.id).join("|")], () => {
    missingIds.value = new Set();
    zipUnavailable.value = false;
    errorMessage.value = "";
});

const entries = computed(() => [
    ...IMPORT_FILE_TYPES.map(({ type }) => ({ key: type, label: FILE_LABELS[type] ?? type, file: props.survey.files.find((file) => file.type === type) ?? null })),
    ...props.survey.files.filter((file) => !knownTypes.has(file.type)).map((file) => ({ key: file.id, label: file.type, file })),
]);

function isAvailable(file: SurveyFile | null): file is SurveyFile {
    return file !== null && fileRemainingDays(file.uploadedAt) > 0 && !missingIds.value.has(file.id);
}

const availableFiles = computed(() => props.survey.files.filter((file) => isAvailable(file)));
const canDownloadZip = computed(() => availableFiles.value.length > 0 && !zipUnavailable.value);

function fileStatus(file: SurveyFile | null): string {
    if (!file) return "ファイルなし（未取込・期限切れ・削除済み）";
    if (missingIds.value.has(file.id)) return "ファイルが見つかりません";
    if (!(fileRemainingDays(file.uploadedAt) > 0)) return `${file.fileName}（保存期限切れ）`;
    return file.fileName;
}

async function download(url: string, filename: string): Promise<void> {
    const { data } = await axios.get<Blob>(url, { responseType: "blob" });
    const objectUrl = URL.createObjectURL(data);
    try {
        const link = document.createElement("a");
        link.href = objectUrl;
        link.download = filename;
        link.click();
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
}

async function downloadFile(file: SurveyFile): Promise<void> {
    if (!isAvailable(file) || downloading.value !== null) return;
    downloading.value = file.id;
    errorMessage.value = "";
    try {
        await download(`/api/survey-files/${file.id}/download`, file.fileName);
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

async function downloadZip(): Promise<void> {
    if (!canDownloadZip.value || downloading.value !== null) return;
    downloading.value = "zip";
    errorMessage.value = "";
    try {
        const filename = `${formatDateTime(props.survey.executedAt).replace(/\D/g, "").slice(0, 12)}_取込ファイル一式.zip`;
        await download(`/api/surveys/${props.survey.id}/files/download`, filename);
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
            zipUnavailable.value = true;
            errorMessage.value = "一括ダウンロードできるファイルが見つかりません。";
        } else {
            errorMessage.value = "一括ダウンロードに失敗しました。時間をおいて再度お試しください。";
        }
    } finally {
        downloading.value = null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-2" data-survey-file-downloads>
        <div v-for="entry in entries" :key="entry.key" class="flex min-w-0 items-center gap-2" :data-file-entry="entry.key">
            <StatusLamp :state="isAvailable(entry.file) ? 'ok' : 'warn'" :label="entry.label" class="w-[92px] shrink-0" />
            <span class="min-w-0 flex-1 truncate font-mono text-[10.5px] text-muted" :title="fileStatus(entry.file)">{{ fileStatus(entry.file) }}</span>
            <BaseButton
                v-if="entry.file"
                variant="ghost"
                size="sm"
                icon="download"
                :aria-label="`${entry.file.fileName}をダウンロード`"
                :disabled="!isAvailable(entry.file) || downloading !== null"
                :loading="downloading === entry.file.id"
                @click="downloadFile(entry.file)"
                >取得</BaseButton
            >
        </div>
        <p v-if="availableFiles.length === 0" class="text-[11px] text-muted">ダウンロード可能な取込ファイルはありません。</p>
        <div class="flex justify-end">
            <BaseButton
                data-testid="survey-files-zip"
                variant="secondary"
                size="sm"
                icon="folder_zip"
                :disabled="!canDownloadZip || downloading !== null"
                :loading="downloading === 'zip'"
                @click="downloadZip"
                >一括ZIPをダウンロード</BaseButton
            >
        </div>
        <BaseAlert v-if="errorMessage" tone="danger">{{ errorMessage }}</BaseAlert>
    </div>
</template>
