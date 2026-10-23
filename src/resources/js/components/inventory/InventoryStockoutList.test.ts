import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import type { InventoryTrendSeries } from "@/api/inventoryTrends";
import InventoryStockoutList from "@/components/inventory/InventoryStockoutList.vue";

const DATES = ["2026-10-16", "2026-10-17", "2026-10-18", "2026-10-19", "2026-10-20", "2026-10-21", "2026-10-22", "2026-10-23"];

/** quantities は 10/16〜10/23 の在庫数。"none" はその日の調査に SKU がない */
function sku(skuCode: string, color: string, size: string, quantities: (number | null | "none")[]): InventoryTrendSeries {
    return {
        skuCode,
        color,
        size,
        points: quantities.map((quantity, index) => {
            const date = DATES[index]!;
            return quantity === "none" ? { date, quantity: null, source: null, basisAt: null } : { date, quantity, source: "survey", basisAt: `${date}T10:00:00+09:00` };
        }),
    };
}

function mountList(series: InventoryTrendSeries[]) {
    return mount(InventoryStockoutList, { props: { series, latestDate: "2026-10-23" } });
}

describe("InventoryStockoutList", () => {
    it("lists the SKUs out of stock on the latest survey, longest streak first", () => {
        const wrapper = mountList([
            // 10/20 まで在庫あり。未確定（10/21）と調査のない日（10/22）は欠品に数えない
            sku("1000-01-M", "01", "M", [5, 4, 3, 3, 2, null, "none", 0]),
            // 10/19 に欠品が始まり、そのあとの調査のない日（10/20）と未確定（10/21）では途切れない
            sku("1000-02-M", "02", "M", [3, 3, 3, 0, "none", null, 0, 0]),
            // 期間内に在庫のあった日がない。未確定の 10/16 は欠品の始まりにしない
            sku("1000-01-L", "01", "L", [null, 0, "none", 0, 0, 0, 0, 0]),
            sku("1000-02-S", "02", "S", [3, 3, 3, 3, 3, 3, 3, 3]),
            sku("1000-02-XL", "02", "XL", [9, 9, 9, 9, 9, 9, 5, 0]),
        ]);

        expect(wrapper.findAll("[data-stockout-sku]").map((row) => row.attributes("data-stockout-sku"))).toEqual(["1000-01-L", "1000-02-M", "1000-01-M", "1000-02-XL"]);
        const [openEnded, continued, gap, latest] = wrapper.findAll("[data-stockout-sku]");
        expect(continued!.get("[data-stockout-streak]").text()).toBe("5日目");
        expect(continued!.get("[data-stockout-since]").text()).toBe("10/19（月）から欠品 · 直前の在庫 3（10/18（日））");
        expect(continued!.get("[data-stockout-period]").text()).toBe("期間中3 日");
        expect(openEnded!.get("[data-stockout-streak]").text()).toBe("7日目以上");
        expect(openEnded!.get("[data-stockout-since]").text()).toBe("10/17（土）から欠品 · 期間内にそれより前の在庫の記録なし");
        expect(openEnded!.get("[data-stockout-period]").text()).toBe("期間中6 日");
        expect(gap!.get("[data-stockout-streak]").text()).toBe("1日目");
        expect(gap!.get("[data-stockout-since]").text()).toBe("10/23（金）から欠品 · 直前の在庫 2（10/20（火））");
        expect(gap!.get("[data-stockout-period]").text()).toBe("期間中1 日");
        expect(gap!.text()).toContain("カラー 01 / M");
        expect(latest!.get("[data-stockout-streak]").text()).toBe("1日目");
        expect(latest!.get("[data-stockout-since]").text()).toBe("10/23（金）から欠品 · 直前の在庫 5（10/22（木））");
    });

    it("says so when no SKU is out of stock on the latest survey", () => {
        const wrapper = mountList([sku("1000-01-M", "01", "M", [0, 0, 0, 0, 0, 0, 0, null]), sku("1000-01-L", "01", "L", [0, 0, 0, 0, 0, 0, 0, 1])]);

        expect(wrapper.find("[data-stockout-list]").exists()).toBe(false);
        expect(wrapper.get("[data-stockout-empty]").text()).toBe("最新の調査で欠品しているSKUはありません。");
    });
});
