<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import { useUiStore } from "@/stores/ui";
import type { SurveyBase } from "@/types";
import { downloadFromApi } from "@/utils/download";
import { fileRemainingDays } from "@/utils/files";
import { formatDateTime } from "@/utils/format";

const props = defineProps<{ survey: SurveyBase }>();

const toast = useUiStore();
const downloading = ref(false);
/** API が「保存ファイルなし」と返した調査では、押せないままにする */
const unavailable = ref(false);

watch([() => props.survey.id, () => props.survey.files.map((file) => file.id).join("|")], () => {
    unavailable.value = false;
});

const hasSavedFiles = computed(() => props.survey.files.some((file) => fileRemainingDays(file.uploadedAt) > 0));

async function downloadZip(): Promise<void> {
    if (!hasSavedFiles.value || unavailable.value || downloading.value) return;
    downloading.value = true;
    try {
        const filename = `${formatDateTime(props.survey.executedAt).replace(/\D/g, "").slice(0, 12)}_取込ファイル一式.zip`;
        await downloadFromApi(`/api/surveys/${props.survey.id}/files/download`, filename);
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
            unavailable.value = true;
            toast.push("一括ダウンロードできるファイルが見つかりません。", "error");
        } else {
            toast.push("一括ダウンロードに失敗しました。時間をおいて再度お試しください。", "error");
        }
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <BaseButton
        data-testid="survey-files-zip"
        variant="ghost"
        size="sm"
        icon="folder_zip"
        :title="hasSavedFiles && !unavailable ? '保存中の取込ファイルをまとめてダウンロードします' : 'ダウンロードできる取込ファイルがありません'"
        :disabled="!hasSavedFiles || unavailable"
        :loading="downloading"
        @click="downloadZip"
        >一括ダウンロード</BaseButton
    >
</template>
