import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import InventoryTrendMiniChart from "@/components/inventory/InventoryTrendMiniChart.vue";

describe("InventoryTrendMiniChart", () => {
    it("draws an ink line and area and ends at a yellow point with an ink outline when the latest stock is zero", () => {
        const wrapper = mount(InventoryTrendMiniChart, {
            props: {
                label: "A-1001-M",
                points: [
                    { date: "2026-10-14", quantity: 4 },
                    { date: "2026-10-15", quantity: 0 },
                    { date: "2026-10-16", quantity: 0 },
                ],
            },
        });

        expect(wrapper.get("[data-mini-chart-line]").attributes("stroke")).toBe("#11161a");
        expect(wrapper.get("[data-mini-chart-area]").attributes("fill")).toBe("#11161a");
        const end = wrapper.get("[data-mini-chart-end]");
        expect(end.attributes("data-zero")).toBe("true");
        expect(end.attributes("fill")).toBe("#f5c400");
        expect(end.attributes("stroke")).toBe("#11161a");
        expect(wrapper.findAll("[data-mini-chart-point] title").map((title) => title.text())).toEqual(["2026/10/14 在庫4点", "2026/10/15 在庫0点", "2026/10/16 在庫0点"]);
    });

    it("ends at an ink point on the last day with data when the latest stock is not zero", () => {
        const wrapper = mount(InventoryTrendMiniChart, {
            props: {
                label: "A-1001-M",
                points: [
                    { date: "2026-10-15", quantity: 0 },
                    { date: "2026-10-16", quantity: 3 },
                    { date: "2026-10-17", quantity: null },
                ],
            },
        });

        const end = wrapper.get("[data-mini-chart-end]");
        expect(end.attributes("data-zero")).toBeUndefined();
        expect(end.attributes("fill")).toBe("#11161a");
    });

    it("renders a compact sparkline without card labels or test hooks", () => {
        const wrapper = mount(InventoryTrendMiniChart, {
            props: {
                label: "A-1001-M",
                variant: "spark",
                points: [
                    { date: "2026-10-15", quantity: 2 },
                    { date: "2026-10-16", quantity: 1 },
                ],
            },
        });

        const svg = wrapper.get("svg");
        expect(svg.attributes("data-sku-sparkline")).toBe("");
        expect(svg.attributes("aria-hidden")).toBe("true");
        expect(svg.attributes("width")).toBe("120");
        expect(wrapper.find("text").exists()).toBe(false);
        expect(wrapper.find("[data-mini-chart-line]").exists()).toBe(false);
        expect(wrapper.findAll("polyline")).toHaveLength(1);
    });
});
