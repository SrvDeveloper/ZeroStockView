import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import type { InventoryTrendSeries } from "@/api/inventoryTrends";
import InventoryColorSizeMatrix from "@/components/inventory/InventoryColorSizeMatrix.vue";

/** quantities は日付 → 在庫数。"none" はその日の調査に SKU がない */
function sku(color: string, size: string, quantities: Record<string, number | null | "none">): InventoryTrendSeries {
    return {
        skuCode: `1000-${color}-${size}`,
        color,
        size,
        points: Object.entries(quantities).map(([date, quantity]) =>
            quantity === "none" ? { date, quantity: null, source: null, basisAt: null } : { date, quantity, source: "survey", basisAt: `${date}T10:00:00+09:00` },
        ),
    };
}

const SERIES = [
    sku("01", "S", { "2026-10-16": 10, "2026-10-23": 13 }),
    sku("01", "M", { "2026-10-16": 5, "2026-10-23": 3 }),
    sku("01", "L", { "2026-10-16": 4, "2026-10-23": 4 }),
    sku("02", "S", { "2026-10-16": 6, "2026-10-20": 2, "2026-10-21": 0, "2026-10-22": "none", "2026-10-23": 0 }),
    sku("02", "M", { "2026-10-16": 8, "2026-10-23": null }),
    sku("03", "S", { "2026-10-23": 20 }),
];

function mountMatrix(series = SERIES, weekAgoDate: string | null = "2026-10-16") {
    return mount(InventoryColorSizeMatrix, { props: { series, latestDate: "2026-10-23", weekAgoDate } });
}

function cell(wrapper: ReturnType<typeof mountMatrix>, color: string, size: string) {
    return wrapper.get(`[data-matrix-color="${color}"] [data-matrix-cell="${size}"]`);
}

describe("InventoryColorSizeMatrix", () => {
    it("lays out colors as rows and sizes as columns in the SKU order, with row headers and totals", () => {
        const wrapper = mountMatrix();

        expect(wrapper.findAll("[data-matrix-size]").map((size) => size.text())).toEqual(["S", "M", "L"]);
        expect(wrapper.findAll('th[scope="row"]').map((heading) => heading.text())).toEqual(["カラー 01", "カラー 02", "カラー 03"]);
        expect(wrapper.findAll("[data-matrix-total]").map((total) => total.text())).toEqual(["20", "—", "20"]);
    });

    it("shows the latest stock with the difference from a week ago", () => {
        const wrapper = mountMatrix();

        expect(cell(wrapper, "01", "S").text()).toBe("13+3");
        expect(cell(wrapper, "01", "S").attributes("data-cell-state")).toBe("inStock");
        expect(cell(wrapper, "01", "M").text()).toBe("3-2");
        expect(cell(wrapper, "01", "L").get("[data-cell-note]").text()).toBe("±0");
        expect(cell(wrapper, "03", "S").text()).toBe("20");
    });

    it("marks stockouts with the streak, low stock with a darker edge, unconfirmed with a dash, and leaves missing SKUs blank", () => {
        const wrapper = mountMatrix();

        const stockout = cell(wrapper, "02", "S");
        expect(stockout.attributes("data-cell-state")).toBe("stockout");
        expect(stockout.classes()).toContain("bg-signal-row");
        expect(stockout.text()).toBe("0欠品 3日目");
        expect(cell(wrapper, "01", "M").attributes("data-cell-state")).toBe("low");
        expect(cell(wrapper, "01", "M").classes()).toContain("shadow-[inset_0_0_0_1px_var(--color-line-stronger)]");
        expect(cell(wrapper, "01", "M").classes()).not.toContain("bg-signal-row");
        expect(cell(wrapper, "02", "M").attributes("data-cell-state")).toBe("pending");
        expect(cell(wrapper, "02", "M").text()).toBe("—");
        expect(cell(wrapper, "02", "L").attributes("data-cell-state")).toBe("missing");
        expect(cell(wrapper, "02", "L").text()).toBe("");
    });

    it("omits the difference when there is no survey to compare with", () => {
        const wrapper = mountMatrix(SERIES, null);

        expect(cell(wrapper, "01", "S").text()).toBe("13");
        expect(cell(wrapper, "02", "S").text()).toBe("0欠品 3日目");
    });
});
