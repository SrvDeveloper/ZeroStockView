import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { InventoryScope, InventoryTrendData, InventoryTrendProductList } from "@/api/inventoryTrends";
import { warehousesOfScope } from "@/constants/warehouses";
import InventoryTrendPage from "@/pages/InventoryTrendPage.vue";

const apiMocks = vi.hoisted(() => ({
    fetchInventoryTrendProducts: vi.fn(),
    fetchInventoryTrend: vi.fn(),
    exportInventoryTrend: vi.fn(),
}));

vi.mock("@/api/inventoryTrends", () => apiMocks);

const productsResponse: InventoryTrendProductList = {
    data: [
        { productCode: "ZS-2408-01", brand: "NORTH HARBOR", category: "トップス", skuCount: 4, hasSurveyHistory: true },
        { productCode: "ZS-2411-07", brand: "NORTH HARBOR", category: "ボトムス", skuCount: 2, hasSurveyHistory: true },
        { productCode: "ZS-2501-03", brand: "FIELD NOTE", category: "バッグ", skuCount: 3, hasSurveyHistory: true },
        { productCode: "API-ONLY", brand: "NORTH HARBOR", category: "トップス", skuCount: 1, hasSurveyHistory: false },
    ],
    brands: ["FIELD NOTE", "NORTH HARBOR"],
    dateRange: { from: "2025-01-01", to: "2026-09-28" },
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
        dailySources: [],
        warehouseTotals: [],
        series: skuCodes.map((skuCode, skuIndex) => ({
            skuCode,
            size: "M",
            points: dates.map((date, dateIndex) => ({
                date,
                quantity: dateIndex === 14 ? 42 : dateIndex === 29 ? 0 : 10 + skuIndex * 5 + dateIndex,
                source: "survey",
                basisAt: `${date}T10:00:00+09:00`,
            })),
        })),
    };
}

/**
 * 応答に倉庫別合計と日別の取得元がなければ、SKU 別の数量から作る。
 * 品番合計は在庫区分の先頭の倉庫に入れ、残りの倉庫は 0 とする。1 SKU でも未確定なら、その日の倉庫はすべて未確定にする。
 */
function withDailyTotals(response: InventoryTrendData): InventoryTrendData {
    if (response.warehouseTotals.length > 0) return response;
    const warehouses = warehousesOfScope(response.scope);
    const dailySources: InventoryTrendData["dailySources"] = [];
    const warehouseTotals = response.dates.map((date) => {
        const points = response.series.flatMap((series) => series.points.filter((point) => point.date === date && point.source !== null));
        const total = points.length === 0 || points.some((point) => point.quantity === null) ? null : points.reduce((sum, point) => sum + (point.quantity ?? 0), 0);
        if (points.length > 0) dailySources.push({ date, source: "survey", basisAt: `${date}T10:00:00+09:00`, amazonInventoryStatus: "complete" });
        const row = { date, amazon: null, boss: null, free: null, stock: null } as InventoryTrendData["warehouseTotals"][number];
        warehouses.forEach((warehouse, index) => (row[warehouse.key] = total === null ? null : index === 0 ? total : 0));
        return row;
    });
    return { ...response, dailySources: response.dailySources.length > 0 ? response.dailySources : dailySources, warehouseTotals };
}

function mountPage(buildTrend: typeof trendResponse = trendResponse) {
    apiMocks.fetchInventoryTrendProducts.mockResolvedValue(productsResponse);
    apiMocks.fetchInventoryTrend.mockImplementation((params) => Promise.resolve(withDailyTotals(buildTrend(params))));

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

    it("shows Amazon-only inventory without the source table and allows daily CSV export", async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
        const wrapper = mountPage((params) => {
            const response = trendResponse(params);
            if (params.productCode === "API-ONLY" && params.scope === "amazon") {
                response.series = [
                    {
                        skuCode: "API-ONLY-M",
                        size: "M",
                        points: response.dates.map((date) => ({
                            date,
                            quantity: date === "2026-09-28" ? 7 : null,
                            source: date === "2026-09-28" ? "sp_api" : null,
                            basisAt: date === "2026-09-28" ? "2026-09-28T09:00:00+09:00" : null,
                        })),
                    },
                ];
            }
            return response;
        });
        await flushPromises();

        expect(wrapper.get('[data-testid="survey-scope-note"]').text()).toContain("Amazonが未確定のSKU");
        expect(wrapper.text()).toContain("調査がない日のAmazon取得結果を含みます");
        await wrapper.get('[data-testid="product-picker-button"]').trigger("click");
        await wrapper.get('[data-product-code="API-ONLY"]').trigger("click");
        await wrapper.get('[data-testid="apply-product-selection"]').trigger("click");
        await wrapper.get('[data-testid="inventory-scope-select"] select').setValue("amazon");
        await flushPromises();

        expect(wrapper.find('[data-testid="amazon-daily-sources"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain("Amazon在庫の取得元");
        expect(wrapper.get('[data-testid="inventory-trend-export"]').attributes("disabled")).toBeUndefined();
        await wrapper.get('[data-testid="inventory-trend-export"]').trigger("click");
        await flushPromises();
        expect(apiMocks.exportInventoryTrend).toHaveBeenCalledWith({ productCode: "API-ONLY", from: "2026-08-30", to: "2026-09-28" });
        expect(click).toHaveBeenCalledOnce();
        expect(wrapper.get('[data-summary-sku="API-ONLY-M"]').text()).toContain("7点");
        expect(wrapper.find('[data-testid="survey-scope-note"]').exists()).toBe(false);
    });

    it("refreshes Amazon quantities without a source table", async () => {
        let refreshed = false;
        const wrapper = mountPage((params) => {
            const response = trendResponse(params);
            if (params.scope === "amazon") {
                response.series[0]!.points.at(-1)!.quantity = refreshed ? 8 : null;
            }
            return response;
        });
        await flushPromises();
        await wrapper.get('[data-testid="inventory-scope-select"] select').setValue("amazon");
        await flushPromises();

        expect(wrapper.get('[data-summary-sku="ZS-2408-01-NV-S"] [data-summary-field="latest"]').text()).not.toBe("8点");
        refreshed = true;
        await wrapper.get('[data-testid="refresh-trend"]').trigger("click");
        await flushPromises();
        expect(wrapper.get('[data-summary-sku="ZS-2408-01-NV-S"] [data-summary-field="latest"]').text()).toBe("8点");
        expect(wrapper.find('[data-testid="amazon-daily-sources"]').exists()).toBe(false);
    });

    it("shows the daily inventory trends for every SKU", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");

        expect(wrapper.text()).toContain("SKU別 在庫数の推移");
        expect(wrapper.text()).toContain("対象品番の全SKU");
        expect(wrapper.text()).toContain("1日単位");
        expect(wrapper.findAll("[data-sku-card]")).toHaveLength(4);
        expect(wrapper.findAll("[data-sku-mini-chart]")).toHaveLength(4);
        expect(wrapper.findAll("[data-mini-chart-area]")).toHaveLength(4);
        expect(wrapper.findAll("[data-mini-chart-line]")).toHaveLength(4);
        expect(wrapper.findAll("[data-mini-chart-point]")).toHaveLength(120);
        expect(wrapper.get('[data-sku-card="ZS-2408-01-NV-S"]').text()).toContain("最新 0点");
        expect(wrapper.get('[data-sku-card="ZS-2408-01-NV-M"]').text()).toContain("期間平均 28.5点");
        expect(wrapper.text()).toContain("SKU別サマリー");
        expect(wrapper.findAll('[data-testid="inventory-scope-select"] option')).toHaveLength(6);
        expect(wrapper.findAll('[data-testid="period-select"] option')).toHaveLength(5);
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

    it("keeps every SKU visible without requiring a SKU selection", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");

        expect(wrapper.findAll("[data-sku-card]")).toHaveLength(4);
        expect(wrapper.find("[data-sku-code]").exists()).toBe(false);
        expect(wrapper.find('svg[aria-label="ZS-2408-01-NV-Mの日次在庫推移"]').exists()).toBe(true);
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

    it("limits a custom target period to 365 days", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('input[aria-label="対象期間の開始日"]').setValue("2025-01-01");
        await flushPromises();

        expect(wrapper.text()).toContain("2025/01/01〜2025/12/31（365日間）");
        expect((wrapper.get('input[aria-label="対象期間の終了日"]').element as HTMLInputElement).value).toBe("2025-12-31");
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ from: "2025-01-01", to: "2025-12-31" }));
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

        expect(wrapper.text()).toContain("BOSSの在庫数を倉庫別に積み上げ、欠品SKU数とあわせて品番単位で表示しています");
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ scope: "boss" }));
    });

    it("uses the product chart and switches to the all-SKU chart grid", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="trend-view-product"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-chart-bar-segment]")).toHaveLength(29);
        expect(wrapper.findAll("[data-stockout-line]")).toHaveLength(1);
        expect(wrapper.find('[data-testid="chart-mode-bar"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="chart-mode-line"]').exists()).toBe(false);

        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");

        expect(wrapper.findAll("[data-sku-mini-chart]")).toHaveLength(4);
        expect(wrapper.findAll("[data-mini-chart-line]")).toHaveLength(4);
        expect(wrapper.find("[data-chart-bar]").exists()).toBe(false);

        await wrapper.get('[data-testid="trend-view-product"]').trigger("click");

        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-stockout-line]")).toHaveLength(1);
    });

    it("labels each SKU mini chart and its daily inventory points", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");

        const firstChart = wrapper.get('svg[aria-label="ZS-2408-01-NV-Sの日次在庫推移"]');
        expect(firstChart.get("desc").text()).toContain("日別在庫数");
        expect(firstChart.findAll("[data-mini-chart-point]")).toHaveLength(30);
        expect(firstChart.findAll("title").at(-1)?.text()).toBe("2026/09/28 在庫0点");
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

    it("marks the day unconfirmed without a partial product total or stockout count when one SKU is unconfirmed", async () => {
        const wrapper = mountPage((params) => {
            const response = trendResponse(params);
            response.series[1]!.points[14]!.quantity = null;

            return response;
        });
        await flushPromises();

        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-chart-bar-pending]")).toHaveLength(1);
        expect(wrapper.findAll("[data-stockout-point]")).toHaveLength(29);
        expect(wrapper.find("[data-legend-pending]").exists()).toBe(true);

        await wrapper.get('[data-bar-pending="true"]').trigger("mouseenter");
        expect(wrapper.get("[data-tooltip-total]").text()).toContain("未確定");
        expect(wrapper.get("[data-tooltip-total]").text()).not.toContain("点");
    });

    it("stacks the product chart by warehouse in the fixed warehouse order", async () => {
        const wrapper = mountPage((params) => {
            const response = trendResponse(params);
            response.scope = params.scope;
            response.dailySources = response.dates.map((date) => ({ date, source: "survey", basisAt: `${date}T10:00:00+09:00`, amazonInventoryStatus: "complete" }));
            response.warehouseTotals = response.dates.map((date, index) => (index === 0 ? { date, amazon: 4, boss: null, free: 2, stock: 1 } : { date, amazon: 4, boss: 3, free: 2, stock: 1 }));

            return response;
        });
        await flushPromises();
        await wrapper.get('[data-testid="inventory-scope-select"] select').setValue("grandTotal");
        await flushPromises();

        expect(wrapper.findAll("[data-legend-warehouse]").map((item) => item.attributes("data-legend-warehouse"))).toEqual(["amazon", "boss", "free", "stock"]);
        const lastDaySegments = wrapper.findAll("[data-chart-bar-segment]").slice(-4);
        expect(lastDaySegments.map((segment) => segment.attributes("fill"))).toEqual(["#e08a2e", "#3a6fb8", "#5f9e3a", "#8e5bb8"]);
        expect(wrapper.findAll("[data-chart-bar-pending]")).toHaveLength(1);

        await wrapper.findAll("[data-chart-bar]").at(-1)!.trigger("mouseenter");
        expect(wrapper.findAll("[data-tooltip-breakdown-row]").map((row) => row.text())).toEqual(["Amazon4点", "BOSS3点", "フリー在庫2点", "ECストック1点"]);
        expect(wrapper.get("[data-tooltip-total]").text()).toContain("総在庫数10点");

        await wrapper.get('[data-bar-pending="true"]').trigger("mouseenter");
        expect(wrapper.findAll("[data-tooltip-breakdown-row]")[1]!.text()).toBe("BOSS未確定");
        expect(wrapper.get("[data-tooltip-total]").text()).toContain("未確定");
    });

    it("keeps the product total when a SKU was not part of that day's survey", async () => {
        const wrapper = mountPage((params) => {
            const response = trendResponse(params);
            const point = response.series[1]!.points[14]!;
            point.quantity = null;
            point.source = null;
            point.basisAt = null;

            return response;
        });
        await flushPromises();

        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-stockout-point]")).toHaveLength(30);
    });
});
