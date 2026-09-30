import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { InventoryScope, InventoryTrendData, InventoryTrendProductList } from "@/api/inventoryTrends";
import InventoryTrendPage from "@/pages/InventoryTrendPage.vue";

const apiMocks = vi.hoisted(() => ({
    fetchInventoryTrendProducts: vi.fn(),
    fetchInventoryTrend: vi.fn(),
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
        vi.clearAllMocks();
    });

    it("shows daily inventory trends loaded from the API for each SKU", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain("SKU別 在庫数の推移");
        expect(wrapper.text()).toContain("1日単位");
        expect(wrapper.findAll("polyline")).toHaveLength(4);
        expect(wrapper.findAll("[data-chart-point]")).toHaveLength(120);
        expect(wrapper.text()).toContain("SKU別サマリー");
        expect(wrapper.findAll('[data-testid="inventory-scope-select"] option')).toHaveLength(6);
        expect(wrapper.findAll('[data-testid="period-select"] option')).toHaveLength(4);
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
        expect(wrapper.findAll("polyline")).toHaveLength(3);
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ productCode: "ZS-2501-03" }));
    });

    it("can hide an SKU while keeping at least one series visible", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const skuButton = wrapper.findAll('button[aria-pressed="true"]').find((button) => button.text().includes("ZS-2408-01-NV-S"));

        await skuButton!.trigger("click");

        expect(wrapper.findAll("polyline")).toHaveLength(3);
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
        expect(wrapper.findAll('circle[tabindex="0"]')).toHaveLength(16);
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ from: "2026-09-25", to: "2026-09-28" }));
    });

    it("updates the target dates from the display period select", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const periodSelect = wrapper.get('[data-testid="period-select"] select');

        await periodSelect.setValue("14");
        await flushPromises();

        expect(wrapper.text()).toContain("2026/09/15〜2026/09/28（14日間）");
        expect(wrapper.findAll("[data-chart-point]")).toHaveLength(56);
    });

    it("requests and displays the selected inventory scope", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const inventoryScopeSelect = wrapper.get('[data-testid="inventory-scope-select"] select');

        await inventoryScopeSelect.setValue("boss");
        await flushPromises();

        expect(wrapper.text()).toContain("BOSSの在庫数を、1日単位で表示しています");
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ scope: "boss" }));
    });

    it("switches between line and bar charts", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="chart-mode-bar"]').trigger("click");

        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-chart-bar-segment]")).toHaveLength(116);
        expect(wrapper.find("polyline").exists()).toBe(false);
        expect(wrapper.get('[data-testid="chart-mode-bar"]').attributes("aria-pressed")).toBe("true");

        await wrapper.get('[data-testid="chart-mode-line"]').trigger("click");

        expect(wrapper.findAll("polyline")).toHaveLength(4);
        expect(wrapper.find("[data-chart-bar]").exists()).toBe(false);
    });

    it("opens the tooltip inward at the right edge of the chart", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const firstSeriesLastPoint = wrapper.findAll('circle[tabindex="0"]')[29]!;

        await firstSeriesLastPoint.trigger("mouseenter");

        const tooltip = wrapper.get("[data-chart-tooltip]");
        expect(tooltip.attributes("style")).toContain("translate(calc(-100% - 14px), -100%)");
        expect(tooltip.attributes("style")).toContain("width: 190px");
        expect(tooltip.text()).toContain("2026/09/28");
        expect(tooltip.text()).toContain("ZS-2408-01-NV-S");
        expect(tooltip.findAll("[data-tooltip-series]")).toHaveLength(4);
    });

    it("places a middle tooltip beside the point instead of over it", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const firstSeriesMiddlePoint = wrapper.findAll('circle[tabindex="0"]')[14]!;

        await firstSeriesMiddlePoint.trigger("mouseenter");

        expect(wrapper.get("[data-chart-tooltip]").attributes("style")).toContain("translate(14px, -50%)");

        await firstSeriesMiddlePoint.trigger("mouseleave");

        expect(wrapper.find("[data-chart-tooltip]").exists()).toBe(false);
    });

    it("shows every SKU in one tooltip when points overlap", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const overlappingPoint = wrapper.findAll('circle[tabindex="0"]')[14]!;

        await overlappingPoint.trigger("mouseenter");

        const tooltip = wrapper.get("[data-chart-tooltip]");
        expect(tooltip.findAll("[data-tooltip-series]")).toHaveLength(4);
        expect(tooltip.text()).toContain("ZS-2408-01-NV-S");
        expect(tooltip.text()).toContain("ZS-2408-01-NV-M");
        expect(tooltip.text()).toContain("ZS-2408-01-WH-M");
        expect(tooltip.text()).toContain("ZS-2408-01-WH-L");
    });
});
