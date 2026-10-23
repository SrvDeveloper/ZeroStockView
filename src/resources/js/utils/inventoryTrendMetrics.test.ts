import { describe, expect, it } from "vitest";
import type { InventoryTrendDailySource, InventoryTrendSeries, InventoryTrendWarehouseTotal } from "@/api/inventoryTrends";
import { changeRate, formatRate, productMetrics, weekAgoDate } from "@/utils/inventoryTrendMetrics";

function source(date: string): InventoryTrendDailySource {
    return { date, source: "survey", basisAt: `${date}T10:00:00+09:00`, amazonInventoryStatus: "complete" };
}

function total(date: string, amazon: number | null, boss: number | null): InventoryTrendWarehouseTotal {
    return { date, amazon, boss, free: 0, stock: 0 };
}

function sku(skuCode: string, quantities: Record<string, number | null>): InventoryTrendSeries {
    return {
        skuCode,
        size: "M",
        points: Object.entries(quantities).map(([date, quantity]) => ({ date, quantity, source: "survey", basisAt: `${date}T10:00:00+09:00` })),
    };
}

describe("weekAgoDate", () => {
    it("uses the survey seven days before", () => {
        expect(weekAgoDate(["2026-10-09", "2026-10-16", "2026-10-15", "2026-10-23"], "2026-10-23")).toBe("2026-10-16");
    });

    it("falls back to the nearest earlier survey within fourteen days", () => {
        expect(weekAgoDate(["2026-10-10", "2026-10-13", "2026-10-20", "2026-10-23"], "2026-10-23")).toBe("2026-10-13");
        expect(weekAgoDate(["2026-10-09", "2026-10-23"], "2026-10-23")).toBe("2026-10-09");
    });

    it("cannot compare beyond fourteen days", () => {
        expect(weekAgoDate(["2026-10-08", "2026-10-20", "2026-10-23"], "2026-10-23")).toBeNull();
    });
});

describe("productMetrics", () => {
    const dailySources = ["2026-10-09", "2026-10-16", "2026-10-22", "2026-10-23"].map(source);
    const warehouseTotals = [total("2026-10-09", 10, 5), total("2026-10-16", 8, 4), total("2026-10-22", null, 3), total("2026-10-23", 3, 2)];
    const series = [
        sku("A", { "2026-10-09": 6, "2026-10-16": 0, "2026-10-22": 0, "2026-10-23": 0 }),
        sku("B", { "2026-10-09": 9, "2026-10-16": 12, "2026-10-22": null, "2026-10-23": 5 }),
        sku("C", { "2026-10-16": 0, "2026-10-23": null }),
    ];

    it("counts confirmed zeros as stockouts, skips unconfirmed days, and compares with a week ago", () => {
        expect(productMetrics("mallTotal", dailySources, warehouseTotals, series)).toEqual({
            latestDate: "2026-10-23",
            latestQuantity: 5,
            weekAgoDate: "2026-10-16",
            weekAgoQuantity: 12,
            // 10/22 は Amazon が未確定なので除く: (15 + 12 + 5) / 3
            average: 11,
            confirmedDays: 3,
            stockoutSkuCount: 1,
            skuCount: 3,
            weekAgoStockoutSkuCount: 2,
            stockoutSkuDays: 4,
            stockoutSkus: 2,
        });
    });

    it("uses only the warehouses of the scope", () => {
        const metrics = productMetrics("boss", dailySources, warehouseTotals, series);
        expect(metrics.latestQuantity).toBe(2);
        expect(metrics.average).toBe(4);
        expect(metrics.confirmedDays).toBe(4);
    });

    it("returns empty metrics without a survey", () => {
        const metrics = productMetrics("mallTotal", [], [], []);
        expect(metrics.latestDate).toBeNull();
        expect(metrics.average).toBeNull();
        expect(metrics.weekAgoStockoutSkuCount).toBeNull();
    });
});

describe("changeRate and formatRate", () => {
    it("formats increases, decreases, no change, and incomparable values", () => {
        expect(formatRate(changeRate(104.2, 100))).toBe("▲ 4.2%");
        expect(formatRate(changeRate(143, 199))).toBe("▼ 28.1%");
        expect(formatRate(changeRate(5, 5))).toBe("±0.0%");
        expect(formatRate(changeRate(null, 5))).toBe("—");
        expect(formatRate(changeRate(5, 0))).toBe("—");
    });
});
