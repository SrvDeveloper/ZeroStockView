<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRouter } from "vue-router";
import SurveyCalendar from "@/components/survey/SurveyCalendar.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseBadge from "@/components/ui/BaseBadge.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import BasePagination from "@/components/ui/BasePagination.vue";
import ConfirmModal from "@/components/ui/ConfirmModal.vue";
import SegmentedControl from "@/components/ui/SegmentedControl.vue";
import StatusLamp, { type LampState } from "@/components/ui/StatusLamp.vue";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { IMPORT_FILE_TYPES, type Survey } from "@/types";
import { formatDateTime } from "@/utils/format";
import { summarizeSurvey } from "@/utils/stock";
import { amazonLampOf, buildCalendar, dailySurveys, localDateKey, previousDailySurvey, uploadedFileCount } from "@/utils/surveyCalendar";

const router = useRouter();
const surveys = useSurveysStore();
const toast = useUiStore();
const refreshing = ref(false);
const refreshError = ref("");

async function refreshHistory() {
    if (refreshing.value) return;
    refreshing.value = true;
    try {
        await surveys.load();
        refreshError.value = "";
    } catch {
        refreshError.value = "調査履歴を更新できませんでした。再読み込みしてください。";
    } finally {
        refreshing.value = false;
    }
}

type HistoryView = "calendar" | "list";
const view = ref<HistoryView>("calendar");
const VIEW_OPTIONS: { value: HistoryView; label: string }[] = [
    { value: "calendar", label: "カレンダー" },
    { value: "list", label: "リスト" },
];

const allList = computed(() => surveys.sortedSurveys);
const latestId = computed(() => surveys.latestSurvey?.id);

const AMAZON_STATUS_LABEL: Record<NonNullable<Survey["amazonInventoryStatus"]>, string> = {
    pending: "Amazon取込待ち",
    partial: "Amazon一部未取得",
    complete: "Amazon取込済み",
    legacy_file: "Amazon旧ファイル",
    unclassified: "Amazon判定不能",
};

function amazonStatusOf(survey: Survey): { state: LampState; label: string } | null {
    const lamp = amazonLampOf(survey);
    if (!lamp || !survey.amazonInventoryStatus) return null;
    return { state: lamp, label: AMAZON_STATUS_LABEL[survey.amazonInventoryStatus] };
}

/* ── カレンダー ── */
const initialMonth = latestId.value ? new Date(surveys.latestSurvey!.executedAt) : new Date();
const calendarYear = ref(initialMonth.getFullYear());
const calendarMonth = ref(initialMonth.getMonth());
const selectedId = ref<string | null>(latestId.value ?? null);

/** 履歴が後から読み込まれたときは、最新の調査がある月と日を選ぶ */
watch(latestId, (id) => {
    if (selectedId.value !== null || !id) return;
    const latest = new Date(surveys.latestSurvey!.executedAt);
    calendarYear.value = latest.getFullYear();
    calendarMonth.value = latest.getMonth();
    selectedId.value = id;
});

const calendarDays = computed(() => buildCalendar(calendarYear.value, calendarMonth.value, allList.value));
const monthLabel = computed(() => `${calendarYear.value}年${calendarMonth.value + 1}月`);
const surveyDayCount = computed(() => calendarDays.value.filter((day) => day.kind === "survey").length);
const elapsedDayCount = computed(() => calendarDays.value.filter((day) => day.kind === "survey" || day.kind === "none").length);

function moveMonth(offset: number) {
    const next = new Date(calendarYear.value, calendarMonth.value + offset, 1);
    calendarYear.value = next.getFullYear();
    calendarMonth.value = next.getMonth();
}

const selectedSurvey = computed(() => {
    const survey = allList.value.find((item) => item.id === selectedId.value);
    return survey && dailySurveys(allList.value).get(localDateKey(survey.executedAt))?.id === survey.id ? survey : null;
});
const selectedSummary = computed(() => (selectedSurvey.value ? summarizeSurvey(selectedSurvey.value) : null));
const selectedPreviousSummary = computed(() => {
    const previous = selectedSurvey.value ? previousDailySurvey(allList.value, selectedSurvey.value) : null;
    return previous ? summarizeSurvey(previous) : null;
});
const selectedTitle = computed(() => {
    if (!selectedSurvey.value) return "";
    const date = new Date(selectedSurvey.value.executedAt);
    return `${date.getMonth() + 1}月${date.getDate()}日（${"日月火水木金土"[date.getDay()]}）の調査`;
});

function compareWithPreviousDay(key: "allZeroCount" | "partialZeroCount"): string {
    if (!selectedSummary.value || !selectedPreviousSummary.value) return "";
    const diff = selectedSummary.value[key] - selectedPreviousSummary.value[key];
    if (diff === 0) return "前日比 ±0";
    return diff > 0 ? `前日比 +${diff}` : `前日比 −${Math.abs(diff)}`;
}

const FILE_LABELS: Record<string, string> = { 倉庫毎の在庫数レポート: "BOSS", KEEP一覧表: "ECストック", 在庫一覧照会表: "フリー在庫" };
const selectedFiles = computed(() =>
    IMPORT_FILE_TYPES.map(({ type }) => ({
        label: FILE_LABELS[type] ?? type,
        file: selectedSurvey.value?.files.find((file) => file.type === type) ?? null,
    })),
);

/* ── リスト ── */
const PER_PAGE = 10;
const page = ref(1);
const startDate = ref("");
const endDate = ref("");

const invalidDateRange = computed(() => startDate.value !== "" && endDate.value !== "" && startDate.value > endDate.value);
const list = computed(() => {
    if (invalidDateRange.value) return [];

    return allList.value.filter((survey) => {
        const surveyDate = localDateKey(survey.executedAt);
        if (startDate.value && surveyDate < startDate.value) return false;
        if (endDate.value && surveyDate > endDate.value) return false;
        return true;
    });
});
const hasActiveFilters = computed(() => startDate.value !== "" || endDate.value !== "");
const totalPages = computed(() => Math.max(1, Math.ceil(list.value.length / PER_PAGE)));
const pagedList = computed(() => list.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));
const increasedIds = computed(() => {
    const ids = new Set<string>();
    for (const day of dailySurveys(allList.value).values()) {
        const previous = previousDailySurvey(allList.value, day);
        if (previous && summarizeSurvey(day).allZeroCount > summarizeSurvey(previous).allZeroCount) ids.add(day.id);
    }
    return ids;
});

watch([startDate, endDate], () => {
    page.value = 1;
});

watch(totalPages, (tp) => {
    if (page.value > tp) page.value = tp;
});

function resetFilters() {
    startDate.value = "";
    endDate.value = "";
}

function skuCount(survey: Survey): number {
    return survey.products.reduce((sum, product) => sum + product.skus.length, 0);
}

/* ── 削除 ── */
const pendingDeleteId = ref<string | null>(null);
const pendingDeleteLabel = computed(() => {
    const survey = allList.value.find((s) => s.id === pendingDeleteId.value);
    return survey ? formatDateTime(survey.executedAt) : "";
});

function requestDelete(id: string) {
    pendingDeleteId.value = id;
}

async function confirmDelete() {
    if (!pendingDeleteId.value) return;
    const deletedId = pendingDeleteId.value;
    await surveys.removeSurvey(deletedId);
    toast.push("調査履歴を削除しました");
    pendingDeleteId.value = null;
    if (selectedId.value === deletedId) selectedId.value = latestId.value ?? null;
}

function openResult(id: string) {
    router.push({ name: "survey-result", params: { id } });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <div>
                <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">在庫調査履歴</h1>
                <p class="mt-1 text-[12.5px] text-ink-2">
                    在庫調査は 1 日に 1 件です。履歴は削除するまで保持されます。全{{ allList.length }}件<span v-if="view === 'list' && hasActiveFilters">中{{ list.length }}件を表示</span>。
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                <SegmentedControl v-model="view" data-history-view :options="VIEW_OPTIONS" label="表示の切り替え" />
                <BaseButton data-testid="refresh-history" variant="secondary" icon="refresh" :loading="refreshing" @click="refreshHistory">最新の在庫を再読込</BaseButton>
            </div>
        </div>
        <p v-if="refreshError" class="text-[12.5px] text-danger">{{ refreshError }}</p>

        <BaseCard v-if="allList.length === 0" :padded="false">
            <BaseEmpty icon="history" title="調査履歴がありません" />
        </BaseCard>

        <div v-else-if="view === 'calendar'" class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_300px] xl:items-start">
            <BaseCard :padded="false">
                <div class="flex flex-wrap items-center gap-1.5 border-b border-line-2 px-3.5 py-2.5">
                    <button type="button" class="flex h-7 w-7 items-center justify-center rounded-chip text-ink-2 hover:bg-tint" aria-label="前の月" @click="moveMonth(-1)"
                        ><AppIcon name="chevron_left" :size="18"
                    /></button>
                    <b class="min-w-24 text-center text-[15px] font-semibold" data-calendar-month>{{ monthLabel }}</b>
                    <button type="button" class="flex h-7 w-7 items-center justify-center rounded-chip text-ink-2 hover:bg-tint" aria-label="次の月" @click="moveMonth(1)"
                        ><AppIcon name="chevron_right" :size="18"
                    /></button>
                    <span class="ml-2.5 text-[11.5px] text-muted"
                        >調査 <b class="font-num text-[17px] font-semibold text-ink">{{ surveyDayCount }}</b> 日 ／ 経過 {{ elapsedDayCount }} 日</span
                    >
                </div>
                <div class="px-3.5 pt-2.5 pb-3.5">
                    <SurveyCalendar :days="calendarDays" :selected-id="selectedSurvey?.id ?? null" @select="(id) => (selectedId = id)" />
                </div>
                <div class="flex flex-wrap items-center gap-x-[18px] gap-y-1.5 border-t border-line-2 bg-surface-2 px-3.5 py-2.5 text-[10.5px] text-muted">
                    <StatusLamp state="ok" label="Amazon 取込済み" />
                    <StatusLamp state="warn" label="Amazon 未確定" />
                    <span class="inline-flex items-center gap-1.5"
                        ><span class="inline-flex gap-[3px]" aria-hidden="true"
                            ><i class="block h-2.5 w-2.5 rounded-[3px] bg-ink"></i><i class="block h-2.5 w-2.5 rounded-[3px] bg-ink"></i
                            ><i class="block h-2.5 w-2.5 rounded-[3px] shadow-[inset_0_0_0_1.5px_var(--color-line-stronger)]"></i></span
                        >取込ファイル数（3 種類中）</span
                    >
                    <span class="inline-flex items-center gap-1.5"
                        ><span class="rounded-[3px] bg-tint px-1.5 text-[10px] text-ink-2">全ゼロ <b class="font-num text-sm text-ink">n</b></span
                        >全倉庫ゼロの SKU 数</span
                    >
                    <span class="inline-flex items-center gap-1.5"><span class="rounded-[3px] bg-signal px-1.5 text-[10px] text-ink">▲</span>前の調査日より増えた日</span>
                </div>
            </BaseCard>

            <BaseCard v-if="selectedSurvey && selectedSummary" :title="selectedTitle" description="同じ日にもう一度取り込むと、この調査を更新します。" :padded="false" data-survey-detail>
                <template #actions><BaseBadge v-if="selectedSurvey.id === latestId" tone="brand">最新</BaseBadge></template>
                <div class="flex flex-col gap-3 px-[18px] py-3.5 text-[11.5px]">
                    <div class="grid grid-cols-2 gap-2">
                        <div class="flex flex-col rounded-chip bg-surface-2 px-2.5 py-1.5">
                            <span class="text-[10.5px] text-muted">全倉庫ゼロ</span>
                            <b class="font-num text-2xl leading-tight font-semibold"
                                ><span class="rounded-[3px] px-1.5" :class="selectedSummary.allZeroCount > 0 ? 'bg-signal' : ''">{{ selectedSummary.allZeroCount }}</span></b
                            >
                            <small class="font-mono text-[10px] text-muted">{{ compareWithPreviousDay("allZeroCount") }}</small>
                        </div>
                        <div class="flex flex-col rounded-chip bg-surface-2 px-2.5 py-1.5">
                            <span class="text-[10.5px] text-muted">一部の倉庫でゼロ</span>
                            <b class="font-num text-2xl leading-tight font-semibold">{{ selectedSummary.partialZeroCount }}</b>
                            <small class="font-mono text-[10px] text-muted">{{ compareWithPreviousDay("partialZeroCount") }}</small>
                        </div>
                        <div class="flex flex-col rounded-chip bg-surface-2 px-2.5 py-1.5">
                            <span class="text-[10.5px] text-muted">品番</span>
                            <b class="font-num text-2xl leading-tight font-semibold">{{ selectedSummary.productCount }}</b>
                        </div>
                        <div class="flex flex-col rounded-chip bg-surface-2 px-2.5 py-1.5">
                            <span class="text-[10.5px] text-muted">SKU</span>
                            <b class="font-num text-2xl leading-tight font-semibold">{{ selectedSummary.skuCount }}</b>
                        </div>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3.5 gap-y-1">
                        <dt class="text-muted">在庫基準日時</dt>
                        <dd class="font-mono">{{ formatDateTime(selectedSurvey.executedAt) }}</dd>
                        <template v-if="selectedSurvey.createdAt">
                            <dt class="text-muted">結果作成日時</dt>
                            <dd class="font-mono">{{ formatDateTime(selectedSurvey.createdAt) }}</dd>
                        </template>
                        <template v-if="selectedSurvey.updatedAt && selectedSurvey.updatedAt !== selectedSurvey.createdAt">
                            <dt class="text-muted">結果更新日時</dt>
                            <dd class="font-mono">{{ formatDateTime(selectedSurvey.updatedAt) }}</dd>
                        </template>
                        <template v-if="amazonStatusOf(selectedSurvey)">
                            <dt class="text-muted">Amazon 在庫</dt>
                            <dd>
                                <StatusLamp :state="amazonStatusOf(selectedSurvey)!.state" :label="amazonStatusOf(selectedSurvey)!.label" />
                            </dd>
                        </template>
                    </dl>
                    <div class="flex flex-col gap-1.5 border-t border-line-2 pt-2.5">
                        <div v-for="entry in selectedFiles" :key="entry.label" class="flex items-center gap-2.5">
                            <StatusLamp :state="entry.file ? 'ok' : 'warn'" :label="entry.label" class="w-[92px] shrink-0" />
                            <span class="min-w-0 truncate font-mono text-[10.5px] text-muted">{{ entry.file?.fileName ?? "ファイルなし" }}</span>
                        </div>
                    </div>
                    <BaseButton variant="primary" block @click="openResult(selectedSurvey.id)">結果を開く</BaseButton>
                    <div class="flex justify-end">
                        <BaseButton variant="danger-ghost" size="sm" icon="delete" @click="requestDelete(selectedSurvey.id)">この日の調査を削除</BaseButton>
                    </div>
                </div>
            </BaseCard>
            <BaseCard v-else :padded="false">
                <BaseEmpty icon="event" title="日付を選んでください" description="調査のある日を選ぶと、その日の内容を表示します。" />
            </BaseCard>
        </div>

        <template v-else>
            <BaseCard title="絞込条件">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                    <div class="w-full lg:w-auto">
                        <span class="mb-1 block text-[11px] font-medium text-ink-2">調査期間</span>
                        <div class="flex items-center gap-2">
                            <input
                                v-model="startDate"
                                data-testid="survey-date-from"
                                type="date"
                                :max="endDate || undefined"
                                aria-label="調査期間の開始日"
                                class="h-8 min-w-0 flex-1 rounded-control border bg-surface px-2.5 font-mono text-xs shadow-field focus:outline-none lg:w-40"
                                :class="invalidDateRange ? 'border-danger ring-3 ring-danger/12' : 'border-line-strong focus:border-ink-2 focus:ring-3 focus:ring-ink/10'"
                            />
                            <span class="text-[12.5px] text-faint">〜</span>
                            <input
                                v-model="endDate"
                                data-testid="survey-date-to"
                                type="date"
                                :min="startDate || undefined"
                                aria-label="調査期間の終了日"
                                class="h-8 min-w-0 flex-1 rounded-control border bg-surface px-2.5 font-mono text-xs shadow-field focus:outline-none lg:w-40"
                                :class="invalidDateRange ? 'border-danger ring-3 ring-danger/12' : 'border-line-strong focus:border-ink-2 focus:ring-3 focus:ring-ink/10'"
                            />
                        </div>
                        <p v-if="invalidDateRange" class="mt-1 text-[11px] text-danger">開始日は終了日以前の日付を指定してください。</p>
                    </div>

                    <BaseButton data-testid="reset-filters" variant="ghost" icon="filter_alt_off" :disabled="!hasActiveFilters" @click="resetFilters"> クリア </BaseButton>
                </div>
            </BaseCard>

            <BaseCard :padded="false">
                <BaseEmpty v-if="list.length === 0" icon="filter_alt_off" title="条件に一致する調査履歴がありません" description="絞込条件を変更するか、クリアしてください。">
                    <BaseButton variant="secondary" @click="resetFilters">絞込条件をクリア</BaseButton>
                </BaseEmpty>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-left text-xs tabular-nums">
                        <thead class="border-b border-line text-[11px] text-muted">
                            <tr>
                                <th class="px-4 py-2.5 font-semibold">在庫基準日時</th>
                                <th class="px-4 py-2.5 text-right font-semibold">品番数</th>
                                <th class="px-4 py-2.5 text-right font-semibold">SKU数</th>
                                <th class="px-4 py-2.5 font-semibold">取込ファイル</th>
                                <th class="px-4 py-2.5 font-semibold">Amazon 在庫</th>
                                <th class="px-4 py-2.5 text-right font-semibold">全倉庫ゼロ</th>
                                <th class="w-44 px-4 py-2.5 text-right font-semibold">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="survey in pagedList" :key="survey.id" :data-survey-id="survey.id" class="border-t border-line-2 first:border-t-0 hover:bg-surface-2">
                                <td class="px-4 py-2">
                                    <span class="flex items-center gap-2">
                                        <span class="font-mono text-[11.5px] font-medium">{{ formatDateTime(survey.executedAt) }}</span>
                                        <BaseBadge v-if="survey.id === latestId" tone="brand">最新</BaseBadge>
                                    </span>
                                    <span v-if="survey.createdAt" class="block font-mono text-[10.5px] text-muted">結果作成: {{ formatDateTime(survey.createdAt) }}</span>
                                    <span v-if="survey.updatedAt && survey.updatedAt !== survey.createdAt" class="block font-mono text-[10.5px] text-muted"
                                        >最終更新: {{ formatDateTime(survey.updatedAt) }}</span
                                    >
                                </td>
                                <td class="px-4 py-2 text-right font-num text-xl font-semibold">{{ survey.products.length }}</td>
                                <td class="px-4 py-2 text-right font-num text-xl font-semibold">{{ skuCount(survey) }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="inline-flex gap-[3px]" aria-hidden="true">
                                            <i
                                                v-for="slot in 3"
                                                :key="slot"
                                                class="block h-2.5 w-2.5 rounded-[3px]"
                                                :class="slot <= uploadedFileCount(survey) ? 'bg-ink' : 'shadow-[inset_0_0_0_1.5px_var(--color-line-stronger)]'"
                                            ></i>
                                        </span>
                                        <span class="font-mono text-[11px]">{{ uploadedFileCount(survey) }}/3</span>
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <StatusLamp v-if="amazonStatusOf(survey)" :state="amazonStatusOf(survey)!.state" :label="amazonStatusOf(survey)!.label" />
                                    <span v-if="survey.amazonBasisAt" class="mt-0.5 block font-mono text-[10.5px] text-muted">Amazon基準: {{ formatDateTime(survey.amazonBasisAt) }}</span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <span
                                        class="inline-block min-w-[30px] rounded-chip px-1.5 text-center font-num text-base font-bold"
                                        :class="increasedIds.has(survey.id) ? 'bg-signal' : 'bg-tint text-ink-2'"
                                        >{{ summarizeSurvey(survey).allZeroCount }}</span
                                    >
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <div class="flex justify-end gap-1.5">
                                        <BaseButton variant="secondary" size="sm" @click="openResult(survey.id)">結果を開く</BaseButton>
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
