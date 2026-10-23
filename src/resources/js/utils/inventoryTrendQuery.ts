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

const SCOPES: readonly InventoryScope[] = ["mallTotal", "amazon", "boss", "free", "stock", "grandTotal"];
const PERIODS: readonly PeriodPreset[] = ["14", "30", "90", "custom"];
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
