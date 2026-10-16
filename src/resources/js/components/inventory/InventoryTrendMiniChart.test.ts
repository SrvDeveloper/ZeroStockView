import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import InventoryTrendChart from "@/components/inventory/InventoryTrendChart.vue";
import InventoryTrendMiniChart from "@/components/inventory/InventoryTrendMiniChart.vue";

describe("stockout colors", () => {
    it("draws stockout SKU counts as yellow markers with a dark outline", () => {
        const wrapper = mount(InventoryTrendChart, {
            props: {
                displayMode: "bar",
                series: [
                    {
                        skuCode: "A-1001",
                        label: "総在庫数",
                        color: "#7d8893",
                        points: [
                            { date: "2026-10-15", quantity: 3 },
                            { date: "2026-10-16", quantity: 2 },
                        ],
                    },
                ],
                stockoutSeries: {
                    label: "欠品SKU数",
                    color: "#11161a",
                    points: [
                        { date: "2026-10-15", quantity: 0 },
                        { date: "2026-10-16", quantity: 1 },
                    ],
                },
            },
        });

        const markers = wrapper.findAll("[data-stockout-point]");
        expect(markers).toHaveLength(2);
        expect(markers.map((marker) => [marker.attributes("fill"), marker.attributes("stroke")])).toEqual([
            ["#f5c400", "#11161a"],
            ["#f5c400", "#11161a"],
        ]);
    });

    it("marks zero days in the SKU mini chart with a yellow point", () => {
        const wrapper = mount(InventoryTrendMiniChart, {
            props: {
                label: "A-1001-M",
                color: "#2563eb",
                points: [
                    { date: "2026-10-15", quantity: 4 },
                    { date: "2026-10-16", quantity: 0 },
                ],
            },
        });

        const points = wrapper.findAll("[data-mini-chart-point]");
        expect(points[0]!.attributes("fill")).toBe("#2563eb");
        expect(points[1]!.attributes("fill")).toBe("#f5c400");
        expect(points[1]!.attributes("stroke")).toBe("#11161a");
    });
});
