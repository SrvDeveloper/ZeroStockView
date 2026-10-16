<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import axios from "axios";
import { useRouter } from "vue-router";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import StatusLamp, { type LampState } from "@/components/ui/StatusLamp.vue";
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
const amazonStatus: Record<AmazonImportStatus, { label: string; lamp: LampState }> = {
    queued: { label: "受付", lamp: "warn" },
    running: { label: "処理中", lamp: "warn" },
    succeeded: { label: "成功", lamp: "ok" },
    failed: { label: "失敗", lamp: "error" },
};
const recentAmazonImports = computed(() => amazonImports.value.slice(0, 5));
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
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div>
                <h1 class="text-[22px] leading-tight font-bold">データ取込</h1>
                <p class="mt-1 text-[12.5px] text-ink-2">BOSS・ECストック・フリー在庫のCSVを1〜3件添付すると、全品番グループの対象品番を取り込みます。Amazon 在庫は自動で取得します。</p>
            </div>
            <BaseButton
                v-if="importSettings.settings.length > 0"
                data-testid="settings-change-button"
                class="sm:ml-auto"
                variant="secondary"
                icon="category"
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

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] xl:items-start">
            <div class="flex min-w-0 flex-col gap-4">
                <BaseCard title="ファイルを添付" description="CSVのヘッダーを確認して、BOSS・ECストック・フリー在庫を自動判定します。ファイル名は判定に使いません。" :padded="false">
                    <div class="flex flex-col gap-3 px-[18px] py-3.5">
                        <div
                            data-testid="bulk-file-dropzone"
                            :data-dragging="bulkDragging ? 'true' : undefined"
                            class="flex flex-col gap-3 rounded-card border-[1.5px] border-dashed p-4 transition sm:flex-row sm:items-center"
                            :class="bulkDragging ? 'border-ink bg-surface ring-3 ring-ink/10' : 'border-line-stronger bg-surface-2'"
                            @dragenter.prevent="bulkDragging = true"
                            @dragover.prevent="bulkDragging = true"
                            @dragleave.self.prevent="bulkDragging = false"
                            @drop.prevent.stop="onBulkDrop"
                        >
                            <AppIcon name="upload_file" :size="26" class="text-muted" />
                            <div>
                                <p class="text-[12.5px] font-semibold">ここにCSVをドロップ</p>
                                <p class="text-[11px] text-muted">1件から実行できます。複数のファイルやフォルダも選べます（最大3件）。</p>
                            </div>
                            <div class="flex flex-wrap gap-1.5 sm:ml-auto">
                                <BaseButton variant="secondary" size="sm" icon="upload_file" :disabled="running" @click="openFilePicker">ファイルを選択</BaseButton>
                                <BaseButton variant="secondary" size="sm" icon="folder_open" :disabled="running" @click="openFolderPicker">フォルダを選択</BaseButton>
                            </div>
                            <input ref="fileInputRef" type="file" class="sr-only" accept=".csv,.txt" multiple aria-label="取込ファイルを選択" @change="onFileInputChange" />
                            <input ref="folderInputRef" type="file" class="sr-only" multiple webkitdirectory aria-label="取込フォルダを選択" @change="onFolderInputChange" />
                        </div>

                        <div v-if="files.length > 0" data-testid="selected-files" class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                            <div
                                v-for="(file, index) in files"
                                :key="`${file.name}-${index}`"
                                data-file-card
                                class="flex min-w-0 items-center gap-2 rounded-card border border-line bg-surface px-3 py-2.5 shadow-raised"
                            >
                                <AppIcon name="description" :size="18" class="shrink-0 text-ink-2" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-[11.5px] text-ink" :title="file.name">{{ file.name }}</p>
                                    <p class="text-[10.5px] text-muted">{{ formatSizeKb(Math.round(file.size / 1024)) }}</p>
                                </div>
                                <button
                                    type="button"
                                    :aria-label="`${file.name}を外す`"
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-chip text-muted hover:bg-tint hover:text-ink"
                                    :disabled="running"
                                    @click="removeFile(index)"
                                >
                                    <AppIcon name="close" :size="16" />
                                </button>
                            </div>
                        </div>
                        <p class="text-[11px] text-muted">種類（BOSS・ECストック・フリー在庫）は取込時に判定します。添付しなかった種類は取込待ちとして登録します。</p>
                    </div>
                    <div class="flex flex-col gap-3 border-t border-line-2 bg-surface-2 px-[18px] py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-[11.5px] text-ink-2">
                            添付ファイル: <span class="font-num text-[17px] font-semibold text-ink">{{ files.length }}件</span>（3件まで）
                            <span v-if="files.length > 0" class="ml-1 text-muted">同じ日の追加取込は履歴を更新します。</span>
                        </p>
                        <BaseButton data-testid="run-import" variant="primary" icon="play_arrow" :disabled="!canRun" :loading="running" @click="runImport">
                            {{ running ? "実行中…" : "取込・調査を実行" }}
                        </BaseButton>
                    </div>
                </BaseCard>
                <BaseAlert v-if="selectionMessage" tone="warning" title="ファイルを確認してください">{{ selectionMessage }}</BaseAlert>
                <BaseAlert v-if="errorMessage" tone="danger" title="取込に失敗しました">{{ errorMessage }}</BaseAlert>
            </div>

            <BaseCard title="Amazon 在庫" description="毎日 9:00 に自動で取得し、同じ日の調査結果に反映します。手動でも取得できます。" :padded="false">
                <template #actions>
                    <StatusLamp
                        v-if="latestAmazonImport"
                        :state="amazonStatus[latestAmazonImport.status].lamp"
                        :label="amazonStatus[latestAmazonImport.status].label"
                        class="text-[11.5px] text-ink-2"
                    />
                </template>
                <div class="flex flex-col gap-3 px-[18px] py-3.5 text-[11.5px]">
                    <div v-if="latestAmazonImport" data-testid="amazon-import-status" class="flex flex-col gap-1.5">
                        <p class="text-ink-2">
                            最新の実行: <span class="font-semibold text-ink">{{ amazonStatus[latestAmazonImport.status].label }}</span> ・
                            {{ latestAmazonImport.triggerSource === "scheduled" ? "定期実行" : "手動実行" }}
                        </p>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3.5 gap-y-1">
                            <dt class="text-muted">受付日時</dt>
                            <dd class="font-mono">{{ formatDateTime(latestAmazonImport.createdAt) }}</dd>
                            <template v-if="latestAmazonImport.basisAt">
                                <dt class="text-muted">在庫基準日時</dt>
                                <dd class="font-mono">{{ formatDateTime(latestAmazonImport.basisAt) }}</dd>
                            </template>
                        </dl>
                        <p v-if="latestAmazonImport.status === 'succeeded'" class="text-ink-2"
                            >対象 SKU: {{ latestAmazonImport.targetSkuCount ?? 0 }} 件 ／ 自社発送の照合: {{ latestAmazonImport.matchedOwnCount ?? 0 }} 件 ／ FBA の照合:
                            {{ latestAmazonImport.matchedFbaCount ?? 0 }} 件</p
                        >
                        <p v-if="latestAmazonImport.errorMessage" class="text-danger">{{ latestAmazonImport.errorMessage }}</p>
                    </div>
                    <p v-else-if="amazonLoading" class="text-muted">状態を確認中…</p>
                    <p v-else class="text-muted">実行履歴はありません。</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <BaseButton data-testid="amazon-import-start" variant="secondary" size="sm" icon="refresh" :disabled="!canStartAmazon" :loading="amazonStarting" @click="startAmazonImport"
                            >Amazon 在庫を取得</BaseButton
                        >
                        <span v-if="activeAmazonImport" class="text-muted">完了まで自動更新します。</span>
                    </div>
                    <p class="text-[10.5px] text-muted">ASIN が登録されていない SKU は Amazon 在庫の取得対象に含まれません。</p>
                    <BaseAlert v-if="amazonError" tone="danger" title="Amazon 在庫取込">{{ amazonError }}</BaseAlert>
                </div>
                <div v-if="recentAmazonImports.length > 0" class="border-t border-line-2 text-[11px]" data-amazon-history>
                    <div class="grid grid-cols-[112px_60px_minmax(0,1fr)] gap-2 bg-surface-2 px-[18px] py-1.5 text-[10.5px] text-muted"> <span>開始</span><span>起動</span><span>結果</span> </div>
                    <div v-for="item in recentAmazonImports" :key="item.id" class="grid grid-cols-[112px_60px_minmax(0,1fr)] items-center gap-2 border-t border-line-2 px-[18px] py-1.5 text-ink-2">
                        <span class="font-mono text-[10.5px] text-ink">{{ formatDateTime(item.createdAt).slice(5) }}</span>
                        <span>{{ item.triggerSource === "scheduled" ? "定期" : "手動" }}</span>
                        <span class="flex min-w-0 items-center gap-2">
                            <StatusLamp :state="amazonStatus[item.status].lamp" :label="amazonStatus[item.status].label" />
                            <span v-if="item.status === 'failed' && item.errorMessage" class="truncate text-muted" :title="item.errorMessage">{{ item.errorMessage }}</span>
                        </span>
                    </div>
                </div>
            </BaseCard>
        </div>
    </div>
</template>
