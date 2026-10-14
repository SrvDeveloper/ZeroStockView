<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import axios from "axios";
import { useRouter } from "vue-router";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import FileDropzone from "@/components/ui/FileDropzone.vue";
import BaseSelect from "@/components/ui/BaseSelect.vue";
import { fetchAmazonInventoryImports, startAmazonInventoryImport, type AmazonInventoryImport, type AmazonImportStatus } from "@/api/amazonInventoryImports";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { IMPORT_FILE_TYPES, type ImportFileType } from "@/types";
import { formatDateTime } from "@/utils/format";

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
const settingOptions = computed(() => importSettings.settings.map((setting) => ({ value: setting.id, label: `${setting.name}（${setting.productCodes.length}件）` })));

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
        formData.append("import_setting_id", importSettings.selectedSettingId as string);
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

        <BaseCard v-if="importSettings.settings.length > 0">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <BaseSelect
                    class="min-w-0 flex-1"
                    :model-value="importSettings.selectedSettingId"
                    :options="settingOptions"
                    label="利用する取込設定"
                    placeholder="取込設定を選択してください"
                    required
                    :disabled="running"
                    @update:model-value="(value) => importSettings.select(value === null ? null : String(value))"
                />
                <BaseButton data-testid="settings-change-button" variant="secondary" icon="settings" :disabled="running" @click="router.push({ name: 'import-settings' })">設定を変更</BaseButton>
            </div>
        </BaseCard>

        <BaseAlert v-if="importSettings.settings.length === 0" tone="warning">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <span>取込設定がありません。先に名前と対象品番を設定してください。</span>
                <BaseButton variant="primary" size="sm" @click="router.push({ name: 'import-settings' })">取込設定へ</BaseButton>
            </div>
        </BaseAlert>

        <BaseCard
            title="Amazon 在庫を取得"
            description="保存済みの取込設定に含まれる品番について、Amazon の自社発送在庫と FBA 出荷可能在庫を取得します。下の5ファイルによる取込・調査とは別の履歴に保存されます。"
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
                :description="`${fileType.stockScope}`"
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
