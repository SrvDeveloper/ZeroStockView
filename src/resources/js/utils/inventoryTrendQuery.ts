import type { LocationQuery, LocationQueryRaw } from "vue-router";
import type { InventoryScope } from "@/api/inventoryTrends";

export type PeriodPreset = "14" | "30" | "90" | "custom";

/** 在庫推移の一覧と詳細で引き継ぐ条件。期間指定（custom）のときだけ開始日と終了日を持つ */
export interface InventoryTrendQuery {
    scope: InventoryScope;
    period: PeriodPreset;
    from: string | null;
    to: string | null;
}

/** 在庫区分の選択肢（一覧と詳細で共通） */
export const INVENTORY_SCOPE_OPTIONS: { value: InventoryScope; label: string }[] = [
    { value: "mallTotal", label: "モール全体" },
    { value: "amazon", label: "Amazon" },
    { value: "boss", label: "BOSS" },
    { value: "free", label: "フリー" },
    { value: "stock", label: "ストック" },
    { value: "grandTotal", label: "総数" },
];

/** 表示期間の選択肢（一覧と詳細で共通）。1年は「期間指定」で選ぶ */
export const PERIOD_OPTIONS: { value: PeriodPreset; label: string }[] = [
    { value: "14", label: "2週間" },
    { value: "30", label: "1か月" },
    { value: "90", label: "3か月" },
    { value: "custom", label: "期間指定" },
];

/** 期間指定で選べる最大の日数（API の上限と同じ） */
export const MAXIMUM_PERIOD_DAYS = 365;

const SCOPES: readonly InventoryScope[] = INVENTORY_SCOPE_OPTIONS.map((option) => option.value);
const PERIODS: readonly PeriodPreset[] = PERIOD_OPTIONS.map((option) => option.value);
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

function single(value: LocationQuery[string] | undefined): string {
    return typeof value === "string" ? value : "";
}

/** URL のクエリから条件を読む。読めない値は初期値（モール全体・1か月）にする */
export function parseInventoryTrendQuery(query: LocationQuery): InventoryTrendQuery {
    const scope = single(query.scope);
    const period = single(query.period);
    const from = single(query.from);
    const to = single(query.to);
    const hasRange = DATE_PATTERN.test(from) && DATE_PATTERN.test(to) && from <= to;

    return {
        scope: SCOPES.includes(scope as InventoryScope) ? (scope as InventoryScope) : "mallTotal",
        period: period === "custom" && !hasRange ? "30" : PERIODS.includes(period as PeriodPreset) ? (period as PeriodPreset) : "30",
        from: period === "custom" && hasRange ? from : null,
        to: period === "custom" && hasRange ? to : null,
    };
}

/** URL のクエリに書く条件。初期値は書かない。期間指定でないときは日付を書かない（最新の調査に合わせて期間が動くように） */
export function buildInventoryTrendQuery(state: InventoryTrendQuery): Record<string, string> {
    const query: Record<string, string> = {};
    if (state.scope !== "mallTotal") query.scope = state.scope;
    if (state.period !== "30") query.period = state.period;
    if (state.period === "custom" && state.from && state.to) {
        query.from = state.from;
        query.to = state.to;
    }
    return query;
}

/** いまのクエリの在庫推移の条件だけを、新しい条件で置き換える（ほかのキーは残す） */
export function replaceInventoryTrendQuery(current: LocationQuery, state: InventoryTrendQuery): LocationQueryRaw {
    const rest = Object.fromEntries(Object.entries(current).filter(([key]) => !["scope", "period", "from", "to"].includes(key)));
    return { ...rest, ...buildInventoryTrendQuery(state) };
}

/** 日付（YYYY-MM-DD）を日数だけずらす */
export function addDays(date: string, amount: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + amount);
    return value.toISOString().slice(0, 10);
}
