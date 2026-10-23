import type { InventoryScope, InventoryTrendDailySource, InventoryTrendSeries, InventoryTrendWarehouseTotal } from "@/api/inventoryTrends";
import type { LampState } from "@/components/ui/StatusLamp.vue";
import { warehousesOfScope } from "@/constants/warehouses";
import { addDays } from "@/utils/inventoryTrendQuery";

/** 前週比は7日前の調査と比べる。なければ、それより前で最も近い調査（14日前まで）と比べる（一覧の集計 API と同じ規則） */
const WEEK_DAYS = 7;
const WEEK_FALLBACK_DAYS = 14;
const WEEKDAYS = ["日", "月", "火", "水", "木", "金", "土"];

/** 詳細画面の上部の、品番の数値 */
export interface InventoryTrendProductMetrics {
    /** 期間内で最新の調査日。調査がなければ null */
    latestDate: string | null;
    /** 最新の調査日の、在庫区分の合計。未確定の倉庫があれば null */
    latestQuantity: number | null;
    weekAgoDate: string | null;
    weekAgoQuantity: number | null;
    /** 確定した日の合計の平均（四捨五入）。確定した日がなければ null */
    average: number | null;
    confirmedDays: number;
    /** 最新の調査日の欠品 SKU 数（確定した 0 だけを数える）と、その日に調査のあった SKU 数 */
    stockoutSkuCount: number;
    skuCount: number;
    weekAgoStockoutSkuCount: number | null;
    /** 期間内の、欠品した SKU × 日の合計と、一度でも欠品した SKU の数 */
    stockoutSkuDays: number;
    stockoutSkus: number;
}

/** 前週比で比べる調査日。surveyDates は調査のある日（順不同） */
export function weekAgoDate(surveyDates: string[], latestDate: string): string | null {
    const target = addDays(latestDate, -WEEK_DAYS);
    const earliest = addDays(latestDate, -WEEK_FALLBACK_DAYS);
    const candidates = surveyDates.filter((date) => date >= earliest && date <= target).sort();
    return candidates.at(-1) ?? null;
}

/** 在庫区分の合計。在庫区分に含まれる倉庫のどれかが未確定（null）なら null */
export function scopeTotal(total: InventoryTrendWarehouseTotal | undefined, scope: InventoryScope): number | null {
    if (!total) return null;
    let sum = 0;
    for (const warehouse of warehousesOfScope(scope)) {
        const value = total[warehouse.key];
        if (value === null) return null;
        sum += value;
    }
    return sum;
}

/** API の応答（warehouseTotals、series、dailySources）から品番の数値を計算する。0 は欠品に数え、null（未確定）は数えない */
export function productMetrics(
    scope: InventoryScope,
    dailySources: InventoryTrendDailySource[],
    warehouseTotals: InventoryTrendWarehouseTotal[],
    series: InventoryTrendSeries[],
): InventoryTrendProductMetrics {
    const surveyDates = dailySources.map((source) => source.date).sort();
    const totalsByDate = new Map(warehouseTotals.map((total) => [total.date, total]));
    const latestDate = surveyDates.at(-1) ?? null;
    const comparedDate = latestDate ? weekAgoDate(surveyDates, latestDate) : null;
    const confirmedTotals = surveyDates.flatMap((date) => {
        const quantity = scopeTotal(totalsByDate.get(date), scope);
        return quantity === null ? [] : [quantity];
    });

    const surveyedPoints = (date: string) =>
        series.flatMap((item) => {
            const point = item.points.find((candidate) => candidate.date === date);
            return point && point.source !== null ? [point] : [];
        });
    const stockoutCount = (date: string) => surveyedPoints(date).filter((point) => point.quantity === 0).length;
    const stockoutDaysBySku = series.map((item) => item.points.filter((point) => point.source !== null && point.quantity === 0).length);

    return {
        latestDate,
        latestQuantity: latestDate ? scopeTotal(totalsByDate.get(latestDate), scope) : null,
        weekAgoDate: comparedDate,
        weekAgoQuantity: comparedDate ? scopeTotal(totalsByDate.get(comparedDate), scope) : null,
        average: confirmedTotals.length > 0 ? Math.round(confirmedTotals.reduce((sum, quantity) => sum + quantity, 0) / confirmedTotals.length) : null,
        confirmedDays: confirmedTotals.length,
        stockoutSkuCount: latestDate ? stockoutCount(latestDate) : 0,
        skuCount: latestDate ? surveyedPoints(latestDate).length : 0,
        weekAgoStockoutSkuCount: comparedDate ? stockoutCount(comparedDate) : null,
        stockoutSkuDays: stockoutDaysBySku.reduce((sum, days) => sum + days, 0),
        stockoutSkus: stockoutDaysBySku.filter((days) => days > 0).length,
    };
}

/** 増減の割合。どちらかが未確定か、比べる日が 0 なら null */
export function changeRate(latest: number | null, weekAgo: number | null): number | null {
    return latest === null || weekAgo === null || weekAgo === 0 ? null : (latest - weekAgo) / weekAgo;
}

/** 例: ▲ 4.2%、▼ 28.1%、±0.0%。比べられないときは — */
export function formatRate(value: number | null): string {
    if (value === null) return "—";
    const percent = Math.abs(value * 100).toFixed(1);
    if (percent === "0.0") return "±0.0%";
    return `${value > 0 ? "▲" : "▼"} ${percent}%`;
}

/** 例: 10/23（金） */
export function formatMonthDay(date: string): string {
    const [year, month, day] = date.split("-").map(Number);
    const weekday = WEEKDAYS[new Date(Date.UTC(year!, month! - 1, day!)).getUTCDay()];
    return `${month}/${day}（${weekday}）`;
}

function formatTime(iso: string): string {
    return new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", hour: "2-digit", minute: "2-digit" }).format(new Date(iso));
}

/** 「最新の調査 10/23（金）・Amazon 取込済み 09:42」のランプの表示 */
export function latestSurveyLamp(survey: Pick<InventoryTrendDailySource, "date" | "amazonInventoryStatus"> & { amazonBasisAt?: string | null }): { state: LampState; label: string; detail: string } {
    const label = `最新の調査 ${formatMonthDay(survey.date)}`;
    if (survey.amazonInventoryStatus === "complete") return { state: "ok", label, detail: survey.amazonBasisAt ? `Amazon 取込済み ${formatTime(survey.amazonBasisAt)}` : "Amazon 取込済み" };
    if (survey.amazonInventoryStatus === "partial") return { state: "warn", label, detail: "Amazon 一部未取得" };
    if (survey.amazonInventoryStatus === "pending") return { state: "warn", label, detail: "Amazon 取込待ち" };
    return { state: "ok", label, detail: "" };
}
