<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import axios from "axios";
import { useRouter } from "vue-router";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import { fetchAmazonInventoryImports, startAmazonInventoryImport, type AmazonInventoryImport, type AmazonImportStatus } from "@/api/amazonInventoryImports";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { formatDateTime, formatSizeKb } from "@/utils/format";

const importSettings = useImportSettingsStore();
const surveys = useSurveysStore();
const toast = useUiStore();
const router = useRouter();

const amazonImports = ref<AmazonInventoryImport[]>([]);
const amazonLoading = ref(true);
const amazonStarting = ref(false);
const amazonError = ref("");
const activeAmazonImport = computed(() => amazonImports.value.find((item) => item.status === "queued" || item.status === "running"));
const latestAmazonImport = computed(() => activeAmazonImport.value ?? amazonImports.value[0]);
const canStartAmazon = computed(() => importSettings.settings.length > 0 && !amazonStarting.value && !activeAmazonImport.value && !amazonLoading.value);
const amazonStatus: Record<AmazonImportStatus, { label: string; tone: "neutral" | "brand" | "success" | "danger" }> = {
    queued: { label: "受付", tone: "neutral" },
    running: { label: "処理中", tone: "brand" },
    succeeded: { label: "成功", tone: "success" },
    failed: { label: "失敗", tone: "danger" },
};
let amazonPollTimer: number | undefined;

async function loadAmazonImports() {
    try {
        amazonImports.value = await fetchAmazonInventoryImports();
        amazonError.value = "";
    } catch {
        amazonError.value = "Amazon在庫取込の状態を取得できませんでした。再読み込みしてください。";
    } finally {
        amazonLoading.value = false;
    }
}

async function startAmazonImport() {
    if (!canStartAmazon.value) return;
    amazonStarting.value = true;
    amazonError.value = "";
    try {
        const importRun = await startAmazonInventoryImport();
        amazonImports.value = [importRun, ...amazonImports.value.filter((item) => item.id !== importRun.id)];
        toast.push("Amazon在庫取込を受け付けました");
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 409) {
            await loadAmazonImports();
        }
        amazonError.value = axios.isAxiosError(error) ? (error.response?.data?.message ?? "Amazon在庫取込を開始できませんでした。") : "Amazon在庫取込を開始できませんでした。";
        toast.push("Amazon在庫取込を開始できませんでした", "error");
    } finally {
        amazonStarting.value = false;
    }
}

watch(activeAmazonImport, (active) => {
    if (amazonPollTimer !== undefined) window.clearInterval(amazonPollTimer);
    amazonPollTimer = active ? window.setInterval(() => void loadAmazonImports(), 5000) : undefined;
});
onMounted(() => void loadAmazonImports());
onUnmounted(() => {
    if (amazonPollTimer !== undefined) window.clearInterval(amazonPollTimer);
});

const files = ref<File[]>([]);
const running = ref(false);
const canRun = computed(() => importSettings.settings.some((setting) => setting.productCodes.length > 0) && files.value.length > 0 && !running.value);
const errorMessage = ref("");
const fileInputRef = ref<HTMLInputElement | null>(null);
const folderInputRef = ref<HTMLInputElement | null>(null);
const selectionMessage = ref("");
const bulkDragging = ref(false);

function openFilePicker() {
    fileInputRef.value?.click();
}

function openFolderPicker() {
    folderInputRef.value?.click();
}

function addFiles(selectedFiles: File[]) {
    if (running.value || selectedFiles.length === 0) return;
    if (files.value.length + selectedFiles.length > 3) {
        selectionMessage.value = "ファイルは3件まで添付できます。不要なファイルを外してから選択してください。";
        return;
    }
    files.value = [...files.value, ...selectedFiles];
    selectionMessage.value = "";
}

function removeFile(index: number) {
    files.value.splice(index, 1);
    selectionMessage.value = "";
}

function onFileInputChange(event: Event) {
    const input = event.target as HTMLInputElement;
    addFiles(Array.from(input.files ?? []));
    input.value = "";
}

function onFolderInputChange(event: Event) {
    const input = event.target as HTMLInputElement;
    addFiles(Array.from(input.files ?? []));
    input.value = "";
}

function onBulkDrop(event: DragEvent) {
    bulkDragging.value = false;
    addFiles(Array.from(event.dataTransfer?.files ?? []));
}

async function runImport() {
    if (!canRun.value || running.value) return;
    errorMessage.value = "";
    running.value = true;

    const formData = new FormData();
    files.value.forEach((file, index) => formData.append(`files[${index}]`, file));

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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">データ取込</h1>
                <p class="mt-1 text-sm text-slate-500">BOSS・ECストック・フリー在庫のCSVを1〜3件添付すると、全品番グループの対象品番を取り込みます。</p>
            </div>
            <BaseButton
                v-if="importSettings.settings.length > 0"
                data-testid="settings-change-button"
                variant="secondary"
                icon="settings"
                :disabled="running"
                @click="router.push({ name: 'import-settings' })"
                >グループを編集</BaseButton
            >
        </div>

        <BaseAlert v-if="importSettings.settings.length === 0" tone="warning">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <span>品番グループがありません。先に名前と対象品番を設定してください。</span>
                <BaseButton variant="primary" size="sm" @click="router.push({ name: 'import-settings' })">品番グループへ</BaseButton>
            </div>
        </BaseAlert>

        <BaseCard
            title="Amazon 在庫を取得"
            description="保存済みの全品番グループに含まれる品番について、Amazon の自社発送在庫と FBA 出荷可能在庫を取得します。ファイルによる取込・調査とは別の履歴に保存されます。"
        >
            <div class="flex flex-col gap-4">
                <p class="text-xs text-slate-500">ASIN が登録されていない SKU は Amazon 在庫の取得対象に含まれません。</p>
                <div class="flex flex-wrap items-center gap-3">
                    <BaseButton data-testid="amazon-import-start" variant="primary" icon="refresh" :disabled="!canStartAmazon" :loading="amazonStarting" @click="startAmazonImport"
                        >Amazon 在庫を取得</BaseButton
                    >
                    <span v-if="amazonLoading" class="text-sm text-slate-500">状態を確認中…</span>
                    <span v-else-if="!latestAmazonImport" class="text-sm text-slate-500">実行履歴はありません。</span>
                    <span v-else-if="activeAmazonImport" class="text-sm text-slate-500">完了まで自動更新します。</span>
                </div>
                <BaseAlert v-if="amazonError" tone="danger" title="Amazon 在庫取込">{{ amazonError }}</BaseAlert>
                <div v-if="latestAmazonImport" data-testid="amazon-import-status" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">最新の実行</span>
                        <BaseBadge :tone="amazonStatus[latestAmazonImport.status].tone">{{ amazonStatus[latestAmazonImport.status].label }}</BaseBadge>
                        <span>{{ latestAmazonImport.triggerSource === "scheduled" ? "定期実行" : "手動実行" }}</span>
                        <span>受付日時: {{ formatDateTime(latestAmazonImport.createdAt) }}</span>
                    </div>
                    <p v-if="latestAmazonImport.basisAt" class="mt-2">在庫基準日時: {{ formatDateTime(latestAmazonImport.basisAt) }}</p>
                    <p v-if="latestAmazonImport.status === 'succeeded'" class="mt-2"
                        >対象 SKU: {{ latestAmazonImport.targetSkuCount ?? 0 }} 件 ／ 自社発送の照合: {{ latestAmazonImport.matchedOwnCount ?? 0 }} 件 ／ FBA の照合:
                        {{ latestAmazonImport.matchedFbaCount ?? 0 }} 件</p
                    >
                    <p v-if="latestAmazonImport.errorMessage" class="mt-2 text-rose-700">{{ latestAmazonImport.errorMessage }}</p>
                </div>
            </div>
        </BaseCard>

        <BaseCard title="ファイルを添付" description="CSVのヘッダーを確認して、BOSS・ECストック・フリー在庫を自動判定します。ファイル名は判定に使いません。">
            <div
                data-testid="bulk-file-dropzone"
                :data-dragging="bulkDragging ? 'true' : undefined"
                class="rounded-xl border-2 border-dashed p-6 transition"
                :class="bulkDragging ? 'border-primary-500 bg-primary-50 ring-4 ring-primary-100' : 'border-slate-300 bg-slate-50/60'"
                @dragenter.prevent="bulkDragging = true"
                @dragover.prevent="bulkDragging = true"
                @dragleave.self.prevent="bulkDragging = false"
                @drop.prevent.stop="onBulkDrop"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">ここにCSVをドロップ</p>
                        <p class="mt-1 text-xs text-slate-500">1件から実行できます。複数のファイルやフォルダも選べます（最大3件）。</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <BaseButton variant="secondary" icon="upload_file" :disabled="running" @click="openFilePicker">ファイルを選択</BaseButton>
                        <BaseButton variant="secondary" icon="folder_open" :disabled="running" @click="openFolderPicker">フォルダを選択</BaseButton>
                    </div>
                    <input ref="fileInputRef" type="file" class="sr-only" accept=".csv,.txt" multiple aria-label="取込ファイルを選択" @change="onFileInputChange" />
                    <input ref="folderInputRef" type="file" class="sr-only" multiple webkitdirectory aria-label="取込フォルダを選択" @change="onFolderInputChange" />
                </div>
            </div>
            <div v-if="files.length > 0" class="mt-4 space-y-2" data-testid="selected-files">
                <div v-for="(file, index) in files" :key="index" class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                    <AppIcon name="description" :size="20" class="shrink-0 text-primary-600" />
                    <span class="min-w-0 flex-1 truncate text-slate-800">{{ file.name }}</span>
                    <span class="shrink-0 text-xs text-slate-500">{{ formatSizeKb(Math.round(file.size / 1024)) }}</span>
                    <button type="button" :aria-label="`${file.name}を外す`" class="rounded p-1 text-slate-500 hover:bg-slate-100" :disabled="running" @click="removeFile(index)">
                        <AppIcon name="close" :size="18" />
                    </button>
                </div>
            </div>
        </BaseCard>
        <BaseAlert v-if="selectionMessage" tone="warning" title="ファイルを確認してください">{{ selectionMessage }}</BaseAlert>

        <BaseAlert v-if="errorMessage" tone="danger" title="取込に失敗しました">{{ errorMessage }}</BaseAlert>

        <BaseCard>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-slate-600">
                    添付ファイル: <span class="font-semibold text-slate-900">{{ files.length }}件</span>
                    <span v-if="files.length > 0" class="ml-2 text-xs text-slate-500">未添付の種類は取込待ちとして表示します。同じ日の追加取込は履歴を更新します。</span>
                </div>
                <BaseButton variant="primary" icon="play_arrow" :disabled="!canRun" :loading="running" @click="runImport">
                    {{ running ? "実行中…" : "取込・調査を実行" }}
                </BaseButton>
            </div>
        </BaseCard>
    </div>
</template>
