import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import InventoryTrendChart from "@/components/inventory/InventoryTrendChart.vue";

function datesFrom(start: string, count: number): string[] {
    return Array.from({ length: count }, (_, index) => {
        const date = new Date(`${start}T00:00:00Z`);
        date.setUTCDate(date.getUTCDate() + index);
        return date.toISOString().slice(0, 10);
    });
}

function warehouseSeries(dates: string[], quantities: Record<string, (number | null)[]>) {
    const colors: Record<string, string> = { amazon: "#e08a2e", boss: "#3a6fb8", free: "#5f9e3a", stock: "#8e5bb8" };
    return Object.entries(quantities).map(([key, values]) => ({
        skuCode: key,
        label: key,
        color: colors[key]!,
        points: dates.map((date, index) => ({ date, quantity: values[index] ?? null })),
    }));
}

describe("InventoryTrendChart", () => {
    it("adds a note to the BOSS row only on the days the note is given for", async () => {
        const dates = datesFrom("2026-10-16", 2);
        const wrapper = mount(InventoryTrendChart, {
            props: {
                series: warehouseSeries(dates, { amazon: [1, 2], boss: [3, 4] }),
                seriesNotes: { boss: { "2026-10-17": "履歴から再現" } },
            },
        });

        await wrapper.findAll("[data-chart-bar]")[1]!.trigger("mouseenter");
        expect(wrapper.findAll("[data-tooltip-breakdown-row]").map((row) => row.text())).toEqual(["amazon2点", "boss（履歴から再現）4点"]);

        await wrapper.findAll("[data-chart-bar]")[0]!.trigger("mouseenter");
        expect(wrapper.find("[data-tooltip-series-note]").exists()).toBe(false);
    });

    it("stacks warehouses bottom-up with gaps and rounds only the top of each bar", () => {
        const dates = datesFrom("2026-10-03", 2);
        const wrapper = mount(InventoryTrendChart, {
            props: { series: warehouseSeries(dates, { amazon: [4, 0], boss: [3, 5], free: [2, 0], stock: [1, 0] }) },
        });

        const segments = wrapper.findAll("[data-chart-bar-segment]");
        expect(segments.map((segment) => segment.attributes("fill"))).toEqual(["#e08a2e", "#3a6fb8", "#5f9e3a", "#8e5bb8", "#3a6fb8"]);
        expect(segments.map((segment) => segment.element.tagName)).toEqual(["rect", "rect", "rect", "path", "path"]);

        const amazon = segments[0]!;
        const boss = segments[1]!;
        const amazonTop = Number(amazon.attributes("y"));
        const bossBottom = Number(boss.attributes("y")) + Number(boss.attributes("height"));
        expect(amazonTop - bossBottom).toBeCloseTo(2);
    });

    it("draws bars at half of each day's width", () => {
        const dates = datesFrom("2026-09-01", 30);
        const wrapper = mount(InventoryTrendChart, { props: { series: warehouseSeries(dates, { amazon: dates.map(() => 5), boss: dates.map(() => 5) }) } });

        const dayWidth = Number(wrapper.findAll("[data-chart-bar]")[0]!.attributes("width"));
        const bottomSegment = wrapper.get("rect[data-chart-bar-segment]");
        expect(Number(bottomSegment.attributes("width"))).toBe(Math.round(dayWidth / 2));
    });

    it("labels every day up to 31 days and thins the labels for longer periods", () => {
        const shortDates = datesFrom("2026-09-29", 5);
        const short = mount(InventoryTrendChart, { props: { series: warehouseSeries(shortDates, { amazon: [1, 1, 1, 1, 1] }) } });
        expect(short.findAll("[data-chart-date]").map((tick) => tick.text())).toEqual(["09-29", "30", "10-01", "02", "03"]);

        const longDates = datesFrom("2026-07-01", 90);
        const long = mount(InventoryTrendChart, { props: { series: warehouseSeries(longDates, { amazon: longDates.map(() => 1) }) } });
        const longTicks = long.findAll("[data-chart-date]").map((tick) => tick.text());
        expect(longTicks).toHaveLength(6);
        expect(longTicks[0]).toBe("07-01");
        expect(longTicks.at(-1)).toBe("09-28");
    });

    it("marks stockout SKU counts under the date axis only on days with stockouts", () => {
        const dates = datesFrom("2026-10-14", 3);
        const wrapper = mount(InventoryTrendChart, {
            props: {
                series: warehouseSeries(dates, { amazon: [3, 2, 1] }),
                stockoutSeries: {
                    label: "欠品SKU数",
                    color: "#f5c400",
                    points: [
                        { date: dates[0]!, quantity: 0 },
                        { date: dates[1]!, quantity: 1 },
                        { date: dates[2]!, quantity: null },
                    ],
                },
            },
        });

        const marks = wrapper.findAll("[data-stockout-point]");
        expect(marks).toHaveLength(1);
        expect(marks[0]!.attributes("data-stockout-count")).toBe("1");
        expect(marks[0]!.get("rect").attributes("fill")).toBe("#f5c400");
        expect(marks[0]!.text()).toBe("1");
        expect(wrapper.text()).toContain("欠品");
        expect(wrapper.find("polyline").exists()).toBe(false);
        expect(wrapper.findAll("[data-chart-bar]")[1]!.attributes("aria-label")).toBe(`${dates[1]}、在庫合計2点、欠品SKU 1件`);
    });

    it("highlights the hovered day and shows the warehouse breakdown, total and stockout SKUs", async () => {
        const dates = datesFrom("2026-09-27", 2);
        const wrapper = mount(InventoryTrendChart, {
            props: {
                series: warehouseSeries(dates, { amazon: [10, 0], boss: [30, 0] }),
                stockoutSeries: {
                    label: "欠品SKU数",
                    color: "#f5c400",
                    points: [
                        { date: dates[0]!, quantity: 0, skus: [], periodStockoutDays: 8 },
                        {
                            date: dates[1]!,
                            quantity: 2,
                            skus: [
                                { skuCode: "SKU-001", stockoutDays: 3 },
                                { skuCode: "SKU-002", stockoutDays: 5 },
                            ],
                            periodStockoutDays: 8,
                        },
                    ],
                },
            },
        });

        expect(wrapper.find("[data-chart-highlight]").exists()).toBe(false);
        await wrapper.findAll("[data-chart-bar]")[0]!.trigger("mouseenter");

        expect(wrapper.find("[data-chart-highlight]").exists()).toBe(true);
        const tooltip = wrapper.get("[data-chart-tooltip]");
        expect(tooltip.classes()).toContain("fixed");
        expect(tooltip.findAll("[data-tooltip-breakdown-row]").map((row) => row.text())).toEqual(["amazon10点", "boss30点"]);
        expect(tooltip.get("[data-tooltip-total]").text()).toBe("総在庫数40点");
        expect(tooltip.get("[data-tooltip-stockout]").text()).toBe("欠品SKU数0SKU");
        expect(tooltip.find("[data-tooltip-stockout-details]").exists()).toBe(false);

        await wrapper.findAll("[data-chart-bar]")[1]!.trigger("mouseenter");
        expect(wrapper.get("[data-tooltip-total]").text()).toBe("総在庫数0点");
        expect(wrapper.findAll("[data-tooltip-stockout-sku]").map((row) => row.text())).toEqual(["SKU-001期間累計 3日", "SKU-002期間累計 5日"]);
        expect(wrapper.get("[data-tooltip-period-stockout-days]").text()).toBe("全SKUの期間累計欠品日数8日");

        await wrapper.findAll("[data-chart-bar]")[1]!.trigger("mouseleave");
        expect(wrapper.find("[data-chart-tooltip]").exists()).toBe(false);
        expect(wrapper.find("[data-chart-highlight]").exists()).toBe(false);
    });

    it("keeps a hoverable unconfirmed bar on a day where no series is confirmed yet", async () => {
        const dates = ["2026-09-26", "2026-09-27"];
        const wrapper = mount(InventoryTrendChart, {
            props: {
                pendingDates: ["2026-09-27"],
                stockoutSeries: {
                    label: "欠品SKU数",
                    color: "#f5c400",
                    points: [
                        { date: "2026-09-26", quantity: 0 },
                        { date: "2026-09-27", quantity: null },
                    ],
                },
                series: [
                    { skuCode: "amazon", label: "Amazon", color: "#e08a2e", points: dates.map((date, index) => ({ date, quantity: index === 0 ? 5 : null })) },
                    { skuCode: "boss", label: "BOSS", color: "#3a6fb8", points: dates.map((date, index) => ({ date, quantity: index === 0 ? 3 : null })) },
                ],
            },
        });

        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(2);
        expect(wrapper.findAll("[data-chart-bar-pending]")).toHaveLength(1);
        const pendingBar = wrapper.get('[data-bar-pending="true"]');
        expect(pendingBar.attributes("aria-label")).toBe("2026-09-27、在庫合計は未確定");

        await pendingBar.trigger("mouseenter");
        expect(wrapper.findAll("[data-tooltip-breakdown-row]").map((row) => row.text())).toEqual(["Amazon未確定", "BOSS未確定"]);
        expect(wrapper.get("[data-tooltip-total]").text()).toBe("総在庫数未確定");
    });
});
