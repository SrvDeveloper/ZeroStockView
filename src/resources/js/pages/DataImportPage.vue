<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import axios from "axios";
import { useRouter } from "vue-router";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import FileDropzone from "@/components/ui/FileDropzone.vue";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { IMPORT_FILE_TYPES, type ImportFileType } from "@/types";

const importSettings = useImportSettingsStore();
const surveys = useSurveysStore();
const toast = useUiStore();
const router = useRouter();

const accepts: Record<ImportFileType, string> = {
    在庫商品レポート: ".txt",
    FBA在庫管理レポート: ".csv",
    倉庫毎の在庫数レポート: ".csv",
    KEEP一覧表: ".csv",
    在庫一覧照会表: ".csv",
};
const expectedFileNames = Object.fromEntries(IMPORT_FILE_TYPES.map(({ type, extension }) => [type, `${type}.${extension}`])) as Record<ImportFileType, string>;

const files = reactive<Record<ImportFileType, File | null>>({
    在庫商品レポート: null,
    FBA在庫管理レポート: null,
    倉庫毎の在庫数レポート: null,
    KEEP一覧表: null,
    在庫一覧照会表: null,
});

const readyFileCount = computed(() => Object.values(files).filter((f) => f !== null).length);
const allFilesReady = computed(() => readyFileCount.value === IMPORT_FILE_TYPES.length);
const canRun = computed(() => importSettings.isConfigured && allFilesReady.value);

const running = ref(false);
const errorMessage = ref("");
const folderInputRef = ref<HTMLInputElement | null>(null);
const folderSelectionMessage = ref("");
const bulkDragging = ref(false);

function setFile(type: ImportFileType, file: File | null) {
    files[type] = file;
    folderSelectionMessage.value = "";
}

function openFolderPicker() {
    folderInputRef.value?.click();
}

function assignImportFiles(selectedFiles: File[]) {
    if (selectedFiles.length === 0) {
        return;
    }
    const missingFileNames: string[] = [];
    const duplicateFileNames: string[] = [];
    for (const fileType of IMPORT_FILE_TYPES) {
        const expectedFileName = expectedFileNames[fileType.type];
        const matches = selectedFiles.filter((file) => file.name === expectedFileName);
        files[fileType.type] = matches.length === 1 ? matches[0] : null;
        if (matches.length === 0) {
            missingFileNames.push(expectedFileName);
        } else if (matches.length > 1) {
            duplicateFileNames.push(expectedFileName);
        }
    }
    const problems: string[] = [];
    if (missingFileNames.length > 0) {
        problems.push(`見つからないファイル: ${missingFileNames.join("、")}`);
    }
    if (duplicateFileNames.length > 0) {
        problems.push(`同名ファイルが複数あります: ${duplicateFileNames.join("、")}`);
    }
    folderSelectionMessage.value = problems.join(" ");
}

function onFolderInputChange(event: Event) {
    const input = event.target as HTMLInputElement;
    assignImportFiles(Array.from(input.files ?? []));
    input.value = "";
}

function onBulkDrop(event: DragEvent) {
    bulkDragging.value = false;
    assignImportFiles(Array.from(event.dataTransfer?.files ?? []));
}

async function runImport() {
    if (!canRun.value || running.value) return;
    errorMessage.value = "";
    running.value = true;

    const formData = new FormData();
    const keys: Record<ImportFileType, string> = {
        在庫商品レポート: "amazon_own",
        FBA在庫管理レポート: "amazon_fba",
        倉庫毎の在庫数レポート: "boss",
        KEEP一覧表: "ec_stock",
        在庫一覧照会表: "free_stock",
    };
    for (const [type, file] of Object.entries(files) as [ImportFileType, File | null][]) {
        if (file) formData.append(keys[type], file);
    }

    try {
        const survey = await surveys.runImport(formData);
        toast.push("取込・調査が完了しました");
        await router.push({ name: "survey-result", params: { id: survey.id } });
    } catch (error) {
        if (axios.isAxiosError(error)) {
            const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
            errorMessage.value = errors ? (Object.values(errors).flat()[0] ?? error.response?.data?.message) : error.response?.data?.message;
        }
        errorMessage.value ||= "取込に失敗しました。ファイルを確認して再実行してください。";
        toast.push("取込に失敗しました", "error");
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">データ取込</h1>
            <p class="mt-1 text-sm text-slate-500">5種類のファイルを添付し、取込・調査を実行します。実行前に取込対象品番の商品情報を最新化します。</p>
        </div>

        <BaseAlert v-if="!importSettings.isConfigured" tone="warning">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <span>取込対象品番が未設定です。先に取込設定で対象品番を選択してください。</span>
                <BaseButton variant="primary" size="sm" @click="router.push({ name: 'import-settings' })">取込設定へ</BaseButton>
            </div>
        </BaseAlert>
        <BaseAlert v-else tone="info">
            取込対象品番: <span class="font-semibold">{{ importSettings.count }}件</span> 設定済み（<RouterLink
                :to="{ name: 'import-settings' }"
                class="text-primary-700 underline hover:text-primary-800"
                >変更する</RouterLink
            >）
        </BaseAlert>

        <BaseCard>
            <div
                data-testid="bulk-file-dropzone"
                class="rounded-lg border-2 border-dashed p-4 transition"
                :class="bulkDragging ? 'border-primary-500 bg-primary-50 ring-4 ring-primary-100' : 'border-slate-300 bg-slate-50/60'"
                @dragenter.prevent="bulkDragging = true"
                @dragover.prevent="bulkDragging = true"
                @dragleave.self.prevent="bulkDragging = false"
                @drop.prevent.stop="onBulkDrop"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">5ファイルを一括選択</p>
                        <p class="mt-1 text-xs text-slate-500">5ファイルをまとめてドラッグ＆ドロップ、またはフォルダを選択すると自動設定します。</p>
                    </div>
                    <BaseButton icon="folder_open" @click="openFolderPicker">フォルダを選択</BaseButton>
                    <input ref="folderInputRef" type="file" class="sr-only" multiple webkitdirectory aria-label="取込フォルダを選択" @change="onFolderInputChange" />
                </div>
            </div>
        </BaseCard>
        <BaseAlert v-if="folderSelectionMessage" tone="warning" title="フォルダを確認してください">{{ folderSelectionMessage }}</BaseAlert>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <FileDropzone
                v-for="fileType in IMPORT_FILE_TYPES"
                :key="fileType.type"
                :accept="accepts[fileType.type]"
                :model-value="files[fileType.type]"
                :title="fileType.type"
                :description="`${fileType.format} ／ ${fileType.stockScope}`"
                :icon="fileType.icon"
                @update:model-value="(f) => setFile(fileType.type, f)"
            />
        </div>

        <BaseAlert v-if="errorMessage" tone="danger" title="取込に失敗しました">{{ errorMessage }}</BaseAlert>

        <BaseCard>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-slate-600">
                    添付状況:
                    <span class="font-semibold" :class="allFilesReady ? 'text-emerald-600' : 'text-slate-900'">{{ readyFileCount }} / {{ IMPORT_FILE_TYPES.length }}</span>
                    ファイル
                </div>
                <BaseButton variant="primary" icon="play_arrow" :disabled="!canRun" :loading="running" @click="runImport">
                    {{ running ? "実行中…" : "取込・調査を実行" }}
                </BaseButton>
            </div>
        </BaseCard>
    </div>
</template>
