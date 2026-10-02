import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { InventoryScope, InventoryTrendData, InventoryTrendProductList } from "@/api/inventoryTrends";
import InventoryTrendPage from "@/pages/InventoryTrendPage.vue";

const apiMocks = vi.hoisted(() => ({
    fetchInventoryTrendProducts: vi.fn(),
    fetchInventoryTrend: vi.fn(),
    exportInventoryTrend: vi.fn(),
}));

vi.mock("@/api/inventoryTrends", () => apiMocks);

const productsResponse: InventoryTrendProductList = {
    data: [
        { productCode: "ZS-2408-01", brand: "NORTH HARBOR", category: "トップス", skuCount: 4 },
        { productCode: "ZS-2411-07", brand: "NORTH HARBOR", category: "ボトムス", skuCount: 2 },
        { productCode: "ZS-2501-03", brand: "FIELD NOTE", category: "バッグ", skuCount: 3 },
    ],
    brands: ["FIELD NOTE", "NORTH HARBOR"],
    dateRange: { from: "2026-07-01", to: "2026-09-28" },
};

function addDays(date: string, amount: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + amount);
    return value.toISOString().slice(0, 10);
}

function datesBetween(from: string, to: string): string[] {
    const dates: string[] = [];
    for (let date = from; date <= to; date = addDays(date, 1)) dates.push(date);
    return dates;
}

function trendResponse(params: { productCode: string; from: string; to: string; scope: InventoryScope }): InventoryTrendData {
    const product = productsResponse.data.find((item) => item.productCode === params.productCode)!;
    const skuCodes = params.productCode === "ZS-2501-03" ? ["ZS-2501-03-NT-F", "ZS-2501-03-BK-F", "ZS-2501-03-KH-F"] : ["ZS-2408-01-NV-S", "ZS-2408-01-NV-M", "ZS-2408-01-WH-M", "ZS-2408-01-WH-L"];
    const dates = datesBetween(params.from, params.to);

    return {
        product,
        scope: params.scope,
        from: params.from,
        to: params.to,
        dates,
        series: skuCodes.map((skuCode, skuIndex) => ({
            skuCode,
            size: "M",
            points: dates.map((date, dateIndex) => ({ date, quantity: dateIndex === 14 ? 42 : dateIndex === 29 ? 0 : 10 + skuIndex * 5 + dateIndex })),
        })),
    };
}

function mountPage(buildTrend: typeof trendResponse = trendResponse) {
    apiMocks.fetchInventoryTrendProducts.mockResolvedValue(productsResponse);
    apiMocks.fetchInventoryTrend.mockImplementation((params) => Promise.resolve(buildTrend(params)));

    return mount(InventoryTrendPage, { global: { stubs: { Teleport: true } } });
}

describe("InventoryTrendPage", () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.clearAllMocks();
        apiMocks.exportInventoryTrend.mockResolvedValue(new Blob(["csv"]));
    });

    it("exports daily inventory CSV by SKU and warehouse for the selected product", async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="inventory-trend-export"]').trigger("click");
        await flushPromises();

        expect(apiMocks.exportInventoryTrend).toHaveBeenCalledWith({
            productCode: "ZS-2408-01",
            from: "2026-08-30",
            to: "2026-09-28",
        });
        expect(click).toHaveBeenCalledOnce();
    });

    it("exports daily inventory CSV for all products in the selected period", async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="inventory-trend-export-all"]').trigger("click");
        await flushPromises();

        expect(apiMocks.exportInventoryTrend).toHaveBeenCalledWith({
            from: "2026-08-30",
            to: "2026-09-28",
        });
        expect(click).toHaveBeenCalledOnce();
    });

    it("shows the daily inventory trend for one selected SKU", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");

        expect(wrapper.text()).toContain("SKU別 在庫数の推移");
        expect(wrapper.text()).toContain("1日単位");
        expect(wrapper.findAll("polyline")).toHaveLength(1);
        expect(wrapper.findAll("[data-chart-point]")).toHaveLength(30);
        expect(wrapper.get('[data-sku-code="ZS-2408-01-NV-S"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.text()).toContain("SKU別サマリー");
        expect(wrapper.findAll('[data-testid="inventory-scope-select"] option')).toHaveLength(6);
        expect(wrapper.findAll('[data-testid="period-select"] option')).toHaveLength(3);
        expect(wrapper.text()).not.toContain("選択してください");
        expect(wrapper.text()).not.toContain("期間を選択");
        expect(wrapper.text()).not.toContain("ダミーデータ");
        expect(apiMocks.fetchInventoryTrend).toHaveBeenCalledWith({
            productCode: "ZS-2408-01",
            from: "2026-08-30",
            to: "2026-09-28",
            scope: "mallTotal",
        });
    });

    it("changes the product and refreshes the displayed SKUs", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="product-picker-button"]').trigger("click");
        await wrapper.get('[data-testid="product-brand-filter"] select').setValue("FIELD NOTE");
        await wrapper.get('input[placeholder="品番・ブランド・カテゴリを入力"]').setValue("バッグ");
        expect(wrapper.findAll("[data-product-code]")).toHaveLength(1);
        await wrapper.get('[data-product-code="ZS-2501-03"]').trigger("click");
        await wrapper.get('[data-testid="apply-product-selection"]').trigger("click");
        await flushPromises();

        expect(wrapper.text()).toContain("ZS-2501-03");
        expect(wrapper.text()).toContain("ZS-2501-03-NT-F");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-stockout-point]")).toHaveLength(30);
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ productCode: "ZS-2501-03" }));
    });

    it("switches the chart to another SKU while keeping one series visible", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");

        await wrapper.get('[data-sku-code="ZS-2408-01-NV-M"]').trigger("click");

        expect(wrapper.findAll("polyline")).toHaveLength(1);
        expect(wrapper.get('[data-sku-code="ZS-2408-01-NV-S"]').attributes("aria-pressed")).toBe("false");
        expect(wrapper.get('[data-sku-code="ZS-2408-01-NV-M"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.get('circle[aria-label^="ZS-2408-01-NV-M"]').attributes("aria-label")).toContain("ZS-2408-01-NV-M");
    });

    it("summarizes each SKU and counts only zero-stock days as stockouts", async () => {
        const wrapper = mountPage((params) => {
            const response = trendResponse(params);
            response.series[0]!.points[0]!.quantity = null;
            return response;
        });
        await flushPromises();

        expect(wrapper.findAll("[data-summary-sku]")).toHaveLength(4);
        expect(wrapper.get('[data-testid="sku-summary-table"]').text()).not.toContain("ステータス");

        const firstSku = wrapper.get('[data-summary-sku="ZS-2408-01-NV-S"]');
        expect(firstSku.get('[data-summary-field="latest"]').text()).toBe("0点");
        expect(firstSku.get('[data-summary-field="average"]').text()).toBe("24.3点");
        expect(firstSku.get('[data-summary-field="minimum"]').text()).toBe("0点");
        expect(firstSku.get('[data-summary-field="maximum"]').text()).toBe("42点");
        expect(firstSku.get('[data-summary-field="stockout-days"]').text()).toBe("1日");
    });

    it("updates the daily chart when a custom target period is selected", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const startInput = wrapper.get('input[aria-label="対象期間の開始日"]');

        await startInput.setValue("2026-09-25");
        await flushPromises();

        expect(wrapper.text()).toContain("2026/09/25〜2026/09/28（4日間）");
        expect((wrapper.get('[data-testid="period-select"] select').element as HTMLSelectElement).value).toBe("custom");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(4);
        expect(wrapper.findAll("[data-stockout-point]")).toHaveLength(4);
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ from: "2026-09-25", to: "2026-09-28" }));
    });

    it("limits a custom target period to 31 days", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('input[aria-label="対象期間の開始日"]').setValue("2026-07-01");
        await flushPromises();

        expect(wrapper.text()).toContain("2026/07/01〜2026/07/31（31日間）");
        expect((wrapper.get('input[aria-label="対象期間の終了日"]').element as HTMLInputElement).value).toBe("2026-07-31");
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ from: "2026-07-01", to: "2026-07-31" }));
    });

    it("updates the target dates from the display period select", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const periodSelect = wrapper.get('[data-testid="period-select"] select');

        await periodSelect.setValue("14");
        await flushPromises();

        expect(wrapper.text()).toContain("2026/09/15〜2026/09/28（14日間）");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(14);
    });

    it("requests and displays the selected inventory scope", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const inventoryScopeSelect = wrapper.get('[data-testid="inventory-scope-select"] select');

        await inventoryScopeSelect.setValue("boss");
        await flushPromises();

        expect(wrapper.text()).toContain("BOSSの総在庫数と欠品SKU数を、品番単位で表示しています");
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ scope: "boss" }));
    });

    it("automatically uses bars for products and a line for SKUs", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="trend-view-product"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-chart-bar-segment]")).toHaveLength(29);
        expect(wrapper.findAll("[data-stockout-line]")).toHaveLength(1);
        expect(wrapper.find('[data-testid="chart-mode-bar"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="chart-mode-line"]').exists()).toBe(false);

        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");

        expect(wrapper.findAll("polyline")).toHaveLength(1);
        expect(wrapper.find("[data-chart-bar]").exists()).toBe(false);

        await wrapper.get('[data-testid="trend-view-product"]').trigger("click");

        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-stockout-line]")).toHaveLength(1);
    });

    it("opens the tooltip inward at the right edge of the chart", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");
        const firstSeriesLastPoint = wrapper.findAll('circle[tabindex="0"]')[29]!;

        await firstSeriesLastPoint.trigger("mouseenter");

        const tooltip = wrapper.get("[data-chart-tooltip]");
        expect(tooltip.attributes("style")).toContain("translate(calc(-100% - 14px), -100%)");
        expect(tooltip.attributes("style")).toContain("width: 190px");
        expect(tooltip.text()).toContain("2026/09/28");
        expect(tooltip.text()).toContain("ZS-2408-01-NV-S");
        expect(tooltip.findAll("[data-tooltip-series]")).toHaveLength(1);
    });

    it("places a middle tooltip beside the point instead of over it", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");
        const firstSeriesMiddlePoint = wrapper.findAll('circle[tabindex="0"]')[14]!;

        await firstSeriesMiddlePoint.trigger("mouseenter");

        expect(wrapper.get("[data-chart-tooltip]").attributes("style")).toContain("translate(14px, 0)");

        await firstSeriesMiddlePoint.trigger("mouseleave");

        expect(wrapper.find("[data-chart-tooltip]").exists()).toBe(false);
    });

    it("shows total inventory and the number of out-of-stock SKUs in product view", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain("品番別 在庫総数・欠品SKU数の推移");
        expect(wrapper.get('[data-testid="trend-view-product"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-stockout-line]")).toHaveLength(1);
        expect(wrapper.findAll("[data-stockout-point]")).toHaveLength(30);

        await wrapper.findAll("[data-chart-bar]")[29]!.trigger("mouseenter");

        const tooltip = wrapper.get("[data-chart-tooltip]");
        expect(tooltip.get("[data-tooltip-total]").text()).toContain("総在庫数0点");
        expect(tooltip.get("[data-tooltip-stockout]").text()).toContain("欠品SKU数4SKU");

        await wrapper.findAll('circle[aria-label*="欠品SKU"]')[29]!.trigger("mouseenter");
        expect(wrapper.get("[data-tooltip-total]").text()).toContain("総在庫数0点");
        expect(wrapper.get("[data-tooltip-stockout]").text()).toContain("欠品SKU数4SKU");
        expect(wrapper.findAll("[data-tooltip-stockout-sku]")).toHaveLength(4);
        expect(wrapper.get("[data-tooltip-stockout-details]").text()).toContain("ZS-2408-01-NV-S");
        expect(wrapper.get("[data-tooltip-stockout-details]").text()).toContain("期間累計 1日");
        expect(wrapper.get("[data-tooltip-period-stockout-days]").text()).toContain("全SKUの期間累計欠品日数4日");
    });
});
