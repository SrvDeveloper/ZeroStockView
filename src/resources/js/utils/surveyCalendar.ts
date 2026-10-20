import type { LampState } from "@/components/ui/StatusLamp.vue";
import { IMPORT_FILE_TYPES, type SurveyBase, type SurveySummary } from "@/types";
import { fileRemainingDays } from "@/utils/files";
import { hasAmazonStock } from "@/utils/stock";

export type CalendarDayKind = "blank" | "survey" | "none" | "future";

export interface CalendarDay {
    kind: CalendarDayKind;
    /** YYYY-MM-DD（ブラウザの現地時刻。運用は日本時間） */
    date: string;
    day: number;
    /** 0 = 日曜 */
    weekday: number;
    survey?: SurveySummary;
    allZeroCount?: number;
    /** 直前の調査日より全倉庫ゼロが増えたか */
    increased?: boolean;
    fileCount?: number;
    amazonLamp?: LampState;
}

export function localDateKey(value: string | Date): string {
    const date = typeof value === "string" ? new Date(value) : value;
    return `${date.getFullYear()}-${`${date.getMonth() + 1}`.padStart(2, "0")}-${`${date.getDate()}`.padStart(2, "0")}`;
}

/** 日付ごとの調査。同じ日に複数ある古いデータは、初回取込日時（executedAt）が最も新しいものを採る */
export function dailySurveys<T extends { executedAt: string }>(surveys: T[]): Map<string, T> {
    const byDate = new Map<string, T>();
    for (const survey of surveys) {
        const key = localDateKey(survey.executedAt);
        const current = byDate.get(key);
        if (!current || new Date(survey.executedAt) > new Date(current.executedAt)) byDate.set(key, survey);
    }
    return byDate;
}

/** 画面から取り込む 3 種類のうち、ファイルが残っている種類の数 */
export function uploadedFileCount(survey: Pick<SurveyBase, "files">): number {
    const types = new Set(survey.files.filter((file) => fileRemainingDays(file.uploadedAt) > 0).map((file) => file.type));
    return IMPORT_FILE_TYPES.filter(({ type }) => types.has(type)).length;
}

export function amazonLampOf(survey: SurveyBase): LampState | undefined {
    if (!hasAmazonStock(survey)) return undefined;
    switch (survey.amazonInventoryStatus) {
        case "complete":
        case "legacy_file":
            return "ok";
        case "pending":
        case "partial":
        case "unclassified":
            return "warn";
        default:
            return survey.amazonSource === "sp_api" ? (survey.amazonBasisAt ? "ok" : "warn") : "ok";
    }
}

/** 調査日ごとの、直前の調査日（調査なしの日は飛ばす）の調査 */
export function previousDailySurvey<T extends { id: string; executedAt: string }>(surveys: T[], survey: { id: string }): T | null {
    const days = [...dailySurveys(surveys).entries()].sort(([left], [right]) => left.localeCompare(right));
    const index = days.findIndex(([, item]) => item.id === survey.id);
    return index > 0 ? days[index - 1]![1] : null;
}

/** 月のカレンダー（日曜始まり）。month は 0〜11 */
export function buildCalendar(year: number, month: number, surveys: SurveySummary[], today: Date = new Date()): CalendarDay[] {
    const byDate = dailySurveys(surveys);
    const orderedKeys = [...byDate.keys()].sort();
    const allZeroByKey = new Map(orderedKeys.map((key) => [key, byDate.get(key)!.allZeroCount]));
    const todayKey = localDateKey(today);
    const first = new Date(year, month, 1);
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const days: CalendarDay[] = [];

    for (let index = 0; index < first.getDay(); index += 1) {
        days.push({ kind: "blank", date: "", day: 0, weekday: index });
    }

    for (let day = 1; day <= daysInMonth; day += 1) {
        const date = new Date(year, month, day);
        const key = localDateKey(date);
        const base = { date: key, day, weekday: date.getDay() };
        const survey = byDate.get(key);
        if (survey) {
            const position = orderedKeys.indexOf(key);
            const previousKey = position > 0 ? orderedKeys[position - 1] : undefined;
            const allZeroCount = allZeroByKey.get(key)!;
            days.push({
                ...base,
                kind: "survey",
                survey,
                allZeroCount,
                increased: previousKey !== undefined && allZeroCount > allZeroByKey.get(previousKey)!,
                fileCount: uploadedFileCount(survey),
                amazonLamp: amazonLampOf(survey),
            });
        } else {
            days.push({ ...base, kind: key > todayKey ? "future" : "none" });
        }
    }

    return days;
}
