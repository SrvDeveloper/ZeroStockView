import { describe, expect, it } from "vitest";
import { buildInventoryTrendQuery, parseInventoryTrendQuery, replaceInventoryTrendQuery } from "@/utils/inventoryTrendQuery";

describe("inventoryTrendQuery", () => {
    it("reads the scope and period and falls back to the defaults", () => {
        expect(parseInventoryTrendQuery({})).toEqual({ scope: "mallTotal", period: "30", from: null, to: null });
        expect(parseInventoryTrendQuery({ scope: "boss", period: "90" })).toEqual({ scope: "boss", period: "90", from: null, to: null });
        expect(parseInventoryTrendQuery({ scope: "unknown", period: "7" })).toEqual({ scope: "mallTotal", period: "30", from: null, to: null });
    });

    it("keeps the dates only for a valid custom period", () => {
        expect(parseInventoryTrendQuery({ period: "custom", from: "2026-09-01", to: "2026-09-10" })).toEqual({ scope: "mallTotal", period: "custom", from: "2026-09-01", to: "2026-09-10" });
        expect(parseInventoryTrendQuery({ period: "custom", from: "2026-09-10", to: "2026-09-01" }).period).toBe("30");
        expect(parseInventoryTrendQuery({ period: "14", from: "2026-09-01", to: "2026-09-10" })).toEqual({ scope: "mallTotal", period: "14", from: null, to: null });
    });

    it("writes only the values that differ from the defaults", () => {
        expect(buildInventoryTrendQuery({ scope: "mallTotal", period: "30", from: "2026-09-01", to: "2026-09-30" })).toEqual({});
        expect(buildInventoryTrendQuery({ scope: "amazon", period: "custom", from: "2026-09-01", to: "2026-09-10" })).toEqual({
            scope: "amazon",
            period: "custom",
            from: "2026-09-01",
            to: "2026-09-10",
        });
    });

    it("replaces only the trend conditions and keeps the other keys", () => {
        expect(replaceInventoryTrendQuery({ group: "setting:1", scope: "boss", from: "2026-09-01" }, { scope: "free", period: "14", from: null, to: null })).toEqual({
            group: "setting:1",
            scope: "free",
            period: "14",
        });
    });
});
