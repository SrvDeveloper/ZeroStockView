import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import type { InventoryScope, InventoryTrendPoint, InventoryTrendSeries } from "@/api/inventoryTrends";
import InventoryTrendHeatmap from "@/components/inventory/InventoryTrendHeatmap.vue";

const DATES = ["2026-10-17", "2026-10-18", "2026-10-19"];

function point(date: string, quantity: number | null, surveyed = true, warehouses?: InventoryTrendPoint["warehouses"]): InventoryTrendPoint {
    return { date, quantity, source: surveyed ? "survey" : null, basisAt: surveyed ? `${date}T10:00:00+09:00` : null, ...(warehouses ? { warehouses } : {}) };
}

function sku(skuCode: string, color: string | null, quantities: (number | null | "none")[]): InventoryTrendSeries {
    return {
        skuCode,
        color,
        size: skuCode.split("-").at(-1)!,
        points: quantities.map((quantity, index) => (quantity === "none" ? point(DATES[index]!, null, false) : point(DATES[index]!, quantity))),
    };
}

const SERIES = [sku("1000-01-S", "01", [12, 8, 0]), sku("1000-01-M", "01", [3, 0, 0]), sku("1000-02-M", "02", [45, null, 30]), sku("1000-02-L", "02", ["none", 6, 5])];

function mountHeatmap(options: { series?: InventoryTrendSeries[]; dates?: string[]; scope?: InventoryScope; withTable?: boolean } = {}) {
    return mount(InventoryTrendHeatmap, {
        props: { productCode: "1000", series: options.series ?? SERIES, dates: options.dates ?? DATES, scope: options.scope ?? "boss" },
        slots: options.withTable === false ? {} : { table: '<table data-testid="summary-table"><tr><td>表</td></tr></table>' },
    });
}

function levels(wrapper: ReturnType<typeof mountHeatmap>, row: number): (string | undefined)[] {
    return DATES.map((_, column) => wrapper.get(`[data-heat-cell="${row}-${column}"]`).attributes("data-heat-level"));
}

function rowOrder(wrapper: ReturnType<typeof mountHeatmap>): (string | undefined)[] {
    return wrapper.findAll("[data-heatmap-row]").map((row) => row.attributes("data-heatmap-row"));
}

describe("InventoryTrendHeatmap", () => {
    it("colors each cell by fixed stock levels, with zero as a stockout and no fill on days without a survey", () => {
        const dates = ["2026-10-01", "2026-10-02", "2026-10-03", "2026-10-04", "2026-10-05", "2026-10-06", "2026-10-07", "2026-10-08", "2026-10-09", "2026-10-10", "2026-10-11", "2026-10-12"];
        const quantities = [0, 1, 4, 5, 9, 10, 19, 20, 39, 40, null];
        const series: InventoryTrendSeries[] = [
            {
                skuCode: "1000-01-M",
                color: "01",
                size: "M",
                points: [...quantities.map((quantity, index) => point(dates[index]!, quantity)), point(dates[11]!, null, false)],
            },
        ];
        const wrapper = mountHeatmap({ series, dates });

        expect(dates.map((_, column) => wrapper.get(`[data-heat-cell="0-${column}"]`).attributes("data-heat-level"))).toEqual([
            "stockout",
            "l1",
            "l1",
            "l2",
            "l2",
            "l3",
            "l3",
            "l4",
            "l4",
            "l5",
            "pending",
            "none",
        ]);
        expect(wrapper.get('[data-heat-cell="0-0"]').classes()).toContain("bg-signal");
        expect(wrapper.get('[data-heat-cell="0-9"]').classes()).toContain("bg-[#3a434c]");
        expect(wrapper.get("[data-heatmap-legend]").text()).toContain("0（欠品）");
        expect(wrapper.get("[data-heatmap-legend]").text()).toContain("40+");
        expect(wrapper.get("[data-heatmap-legend]").text()).toContain("未確定");
        expect(wrapper.get("[data-heatmap-legend]").text()).toContain("調査なし");
    });

    it("shows the latest stock and stockout days of each SKU and summarizes the figure for screen readers", () => {
        const wrapper = mountHeatmap();

        expect(levels(wrapper, 2)).toEqual(["l5", "pending", "l4"]);
        expect(levels(wrapper, 3)).toEqual(["none", "l2", "l2"]);
        expect(wrapper.findAll("[data-heatmap-latest]").map((cell) => cell.text())).toEqual(["0", "0", "30", "5"]);
        expect(wrapper.findAll("[data-heatmap-stockout-days]").map((cell) => cell.text())).toEqual(["1", "2", "—", "—"]);
        expect(wrapper.get("[data-heatmap-grid]").attributes("aria-label")).toBe("1000のSKU別の在庫（2026/10/17〜2026/10/19、4SKU、欠品のあったSKU 2）");
    });

    it("groups rows under color headings only in the color and size order", async () => {
        const wrapper = mountHeatmap();

        expect(wrapper.findAll("[data-heatmap-color]").map((heading) => heading.text())).toEqual(["カラー 01", "カラー 02"]);
        expect(rowOrder(wrapper)).toEqual(["1000-01-S", "1000-01-M", "1000-02-M", "1000-02-L"]);

        await wrapper.get('[data-testid="heatmap-sort"] select').setValue("stockoutDays");
        expect(rowOrder(wrapper)).toEqual(["1000-01-M", "1000-01-S", "1000-02-M", "1000-02-L"]);
        expect(wrapper.find("[data-heatmap-color]").exists()).toBe(false);

        await wrapper.get('[data-testid="heatmap-sort"] select').setValue("latest");
        expect(rowOrder(wrapper)).toEqual(["1000-01-S", "1000-01-M", "1000-02-L", "1000-02-M"]);
        expect(wrapper.find("[data-heatmap-color]").exists()).toBe(false);
    });

    it("labels days every other day and colors weekend dates", () => {
        const wrapper = mountHeatmap();
        const dates = wrapper.findAll("[data-heatmap-date]");

        expect(dates.map((date) => date.text())).toEqual(["10/17", "", "19"]);
        expect(dates[0]!.classes()).toContain("text-saturday");
        expect(dates[1]!.classes()).toContain("text-sunday");
        expect(dates[2]!.classes()).toContain("text-muted");
    });

    it("shows the quantity in a tooltip on hover, with the warehouse breakdown for combined scopes", async () => {
        const series = [
            {
                skuCode: "1000-01-M",
                color: "01",
                size: "M",
                points: [point(DATES[0]!, 12, true, { amazon: 5, boss: 7 }), point(DATES[1]!, null, true, { amazon: null, boss: 4 }), point(DATES[2]!, null, false)],
            },
        ];
        const wrapper = mountHeatmap({ series, scope: "mallTotal" });

        await wrapper.get('[data-heat-cell="0-0"]').trigger("mouseenter");
        const tooltip = wrapper.get("[data-heatmap-tooltip]");
        expect(tooltip.text()).toContain("10/17（土）");
        expect(tooltip.text()).toContain("1000-01-M");
        expect(tooltip.get("[data-tooltip-quantity]").text()).toBe("在庫 12点");
        expect(tooltip.findAll("[data-tooltip-breakdown-row]").map((row) => row.text())).toEqual(["Amazon5点", "BOSS7点"]);

        await wrapper.get('[data-heat-cell="0-1"]').trigger("mouseenter");
        expect(wrapper.get("[data-tooltip-quantity]").text()).toBe("未確定");
        expect(wrapper.findAll("[data-tooltip-breakdown-row]").map((row) => row.text())).toEqual(["Amazon未確定", "BOSS4点"]);

        await wrapper.get('[data-heat-cell="0-2"]').trigger("mouseenter");
        expect(wrapper.get("[data-tooltip-quantity]").text()).toBe("調査なし");
        expect(wrapper.find("[data-tooltip-breakdown]").exists()).toBe(false);

        await wrapper.get('[data-heat-cell="0-2"]').trigger("mouseleave");
        expect(wrapper.find("[data-heatmap-tooltip]").exists()).toBe(false);
    });

    it("moves between cells with the arrow keys and keeps a single tab stop", async () => {
        const wrapper = mountHeatmap({ scope: "boss" });
        document.body.appendChild(wrapper.element);

        expect(wrapper.findAll('[data-heat-cell][tabindex="0"]').map((cell) => cell.attributes("data-heat-cell"))).toEqual(["0-0"]);
        expect(wrapper.get('[data-heat-cell="0-0"]').attributes("aria-label")).toBe("10/17（土） 1000-01-S 在庫 12");

        await wrapper.get('[data-heat-cell="0-0"]').trigger("focus");
        expect(wrapper.get("[data-tooltip-quantity]").text()).toBe("在庫 12点");
        expect(wrapper.find("[data-tooltip-breakdown]").exists()).toBe(false);

        await wrapper.get('[data-heat-cell="0-0"]').trigger("keydown", { key: "ArrowRight" });
        await wrapper.vm.$nextTick();
        expect(document.activeElement?.getAttribute("data-heat-cell")).toBe("0-1");
        await wrapper.get('[data-heat-cell="0-1"]').trigger("keydown", { key: "ArrowDown" });
        await wrapper.vm.$nextTick();
        expect(document.activeElement?.getAttribute("data-heat-cell")).toBe("1-1");
        expect(wrapper.get("[data-tooltip-quantity]").text()).toBe("在庫 0点");
        await wrapper.get('[data-heat-cell="1-1"]').trigger("keydown", { key: "End" });
        await wrapper.vm.$nextTick();
        expect(document.activeElement?.getAttribute("data-heat-cell")).toBe("1-2");
        expect(wrapper.findAll('[data-heat-cell][tabindex="0"]').map((cell) => cell.attributes("data-heat-cell"))).toEqual(["1-2"]);

        wrapper.unmount();
    });

    it("opens and closes the numeric table", async () => {
        const wrapper = mountHeatmap();
        const toggle = wrapper.get('[data-testid="heatmap-table-toggle"]');

        expect(toggle.attributes("aria-expanded")).toBe("false");
        expect(wrapper.find('[data-testid="summary-table"]').exists()).toBe(false);
        await toggle.trigger("click");
        expect(toggle.attributes("aria-expanded")).toBe("true");
        expect(toggle.text()).toContain("表を閉じる");
        expect(wrapper.find('[data-testid="summary-table"]').exists()).toBe(true);
        expect(wrapper.emitted("update:tableOpen")).toEqual([[true]]);
        await toggle.trigger("click");
        expect(wrapper.find('[data-testid="summary-table"]').exists()).toBe(false);
    });

    it("hides the table button without a table", () => {
        const wrapper = mountHeatmap({ withTable: false });

        expect(wrapper.find('[data-testid="heatmap-table-toggle"]').exists()).toBe(false);
    });
});
