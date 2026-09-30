import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import InventoryTrendChart from "@/components/inventory/InventoryTrendChart.vue";

describe("InventoryTrendChart", () => {
    it("connects the points around missing dates with a dashed line", () => {
        const wrapper = mount(InventoryTrendChart, {
            props: {
                series: [
                    {
                        skuCode: "SKU-001",
                        label: "SKU-001",
                        color: "#2563eb",
                        points: [
                            { date: "2026-09-25", quantity: 10 },
                            { date: "2026-09-26", quantity: 12 },
                            { date: "2026-09-27", quantity: null },
                            { date: "2026-09-28", quantity: 20 },
                            { date: "2026-09-29", quantity: 21 },
                        ],
                    },
                ],
            },
        });

        expect(wrapper.findAll('[data-line-style="solid"]')).toHaveLength(2);
        expect(wrapper.findAll('[data-line-style="dashed"]')).toHaveLength(1);
        expect(wrapper.get('[data-line-style="dashed"]').attributes("stroke-dasharray")).toBe("3 5");
        expect(wrapper.findAll("[data-chart-point]")).toHaveLength(4);
    });

    it("renders one stacked bar per date and shows each SKU's share of the daily total", async () => {
        const wrapper = mount(InventoryTrendChart, {
            props: {
                displayMode: "bar",
                series: [
                    {
                        skuCode: "SKU-001",
                        label: "SKU-001",
                        color: "#2563eb",
                        points: [
                            { date: "2026-09-27", quantity: 10 },
                            { date: "2026-09-28", quantity: null },
                            { date: "2026-09-29", quantity: 0 },
                        ],
                    },
                    {
                        skuCode: "SKU-002",
                        label: "SKU-002",
                        color: "#7c3aed",
                        points: [
                            { date: "2026-09-27", quantity: 30 },
                            { date: "2026-09-28", quantity: null },
                            { date: "2026-09-29", quantity: 0 },
                        ],
                    },
                ],
            },
        });

        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(2);
        expect(wrapper.findAll("[data-chart-bar-segment]")).toHaveLength(2);
        expect(wrapper.find("polyline").exists()).toBe(false);

        await wrapper.findAll("[data-chart-bar]")[0]!.trigger("mouseenter");

        const tooltip = wrapper.get("[data-chart-tooltip]");
        expect(tooltip.classes()).toContain("fixed");
        expect(tooltip.text()).toContain("合計 40点");
        expect(tooltip.text()).toContain("SKU-00110点25%");
        expect(tooltip.text()).toContain("SKU-00230点75%");
    });

    it("uses columns to keep a many-SKU tooltip compact", async () => {
        const wrapper = mount(InventoryTrendChart, {
            props: {
                displayMode: "bar",
                series: Array.from({ length: 13 }, (_, index) => ({
                    skuCode: `SKU-${String(index + 1).padStart(3, "0")}`,
                    label: `SKU-${String(index + 1).padStart(3, "0")}`,
                    color: "#2563eb",
                    points: [{ date: "2026-09-29", quantity: index + 1 }],
                })),
            },
        });

        await wrapper.get("[data-chart-bar]").trigger("mouseenter");

        const tooltip = wrapper.get("[data-chart-tooltip]");
        expect(tooltip.attributes("style")).toContain("width: 330px");
        expect(tooltip.get(".grid").attributes("style")).toContain("repeat(2, minmax(0, 1fr))");
        expect(tooltip.findAll("[data-tooltip-series]")).toHaveLength(13);
    });
});
