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

const routerMocks = vi.hoisted(() => ({ route: { query: {} as Record<string, string> }, push: vi.fn(), replace: vi.fn() }));

vi.mock("@/api/inventoryTrends", () => apiMocks);
vi.mock("vue-router", () => ({ useRoute: () => routerMocks.route, useRouter: () => ({ push: routerMocks.push, replace: routerMocks.replace }) }));

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

async function openExportMenu(wrapper: ReturnType<typeof mountPage>) {
    await wrapper.get('[data-testid="inventory-trend-export-button"]').trigger("click");
}

function mountPage(buildTrend: typeof trendResponse = trendResponse, productCode = "ZS-2408-01") {
    apiMocks.fetchInventoryTrendProducts.mockResolvedValue(productsResponse);
    apiMocks.fetchInventoryTrend.mockImplementation((params) => Promise.resolve(withDailyTotals(buildTrend(params))));

    return mount(InventoryTrendPage, { props: { productCode }, global: { stubs: { Teleport: true } } });
}

describe("InventoryTrendPage", () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.clearAllMocks();
        apiMocks.exportInventoryTrend.mockResolvedValue(new Blob(["csv"]));
        routerMocks.route.query = {};
    });

    it("opens the product of the route without a product picker", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get("h1").text()).toBe("ZS-2408-01");
        expect(wrapper.text()).toContain("NORTH HARBOR ・ トップス ・ 4 SKU");
        expect(wrapper.find('[data-testid="product-picker-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="selected-product"]').exists()).toBe(false);
        expect(apiMocks.fetchInventoryTrend).toHaveBeenCalledWith({ productCode: "ZS-2408-01", from: "2026-08-30", to: "2026-09-28", scope: "mallTotal" });
    });

    it("shows an empty state for a product without a survey and leads back to the list", async () => {
        const wrapper = mountPage(trendResponse, "UNKNOWN-01");
        await flushPromises();

        expect(wrapper.text()).toContain("この品番の在庫推移はありません");
        expect(wrapper.find('[data-testid="inventory-scope-select"]').exists()).toBe(false);
        expect(apiMocks.fetchInventoryTrend).not.toHaveBeenCalled();
        await wrapper.get('[data-testid="back-to-list"]').trigger("click");
        expect(routerMocks.push).toHaveBeenCalledWith({ name: "inventory-trends", query: {} });
    });

    it("goes back to the list with the list conditions it was opened with", async () => {
        routerMocks.route.query = { focus: "stockout", sort: "delta", page: "2" };
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="back-to-list"]').trigger("click");
        expect(routerMocks.push).toHaveBeenCalledWith({ name: "inventory-trends", query: { focus: "stockout", sort: "delta", page: "2" } });
    });

    it("restores the scope and period from the URL and keeps changes in the URL", async () => {
        routerMocks.route.query = { scope: "boss", period: "custom", from: "2026-09-01", to: "2026-09-10" };
        const wrapper = mountPage();
        await flushPromises();

        expect(apiMocks.fetchInventoryTrend).toHaveBeenCalledWith({ productCode: "ZS-2408-01", from: "2026-09-01", to: "2026-09-10", scope: "boss" });
        expect(wrapper.get('[data-testid="period-select"] [aria-checked="true"]').text()).toBe("期間指定");

        await wrapper.get('[data-testid="inventory-scope-select"] select').setValue("amazon");
        expect(routerMocks.replace).toHaveBeenLastCalledWith({ query: { scope: "amazon", period: "custom", from: "2026-09-01", to: "2026-09-10" } });
        const twoWeeks = wrapper.findAll('[data-testid="period-select"] [role="radio"]').find((option) => option.text() === "2週間")!;
        await twoWeeks.trigger("click");
        expect(routerMocks.replace).toHaveBeenLastCalledWith({ query: { scope: "amazon", period: "14" } });

        await wrapper.get('[data-testid="back-to-list"]').trigger("click");
        expect(routerMocks.push).toHaveBeenCalledWith({ name: "inventory-trends", query: { scope: "amazon", period: "14" } });
    });

    it("exports daily inventory CSV by SKU and warehouse for the selected product", async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.find('[data-testid="inventory-trend-export"]').exists()).toBe(false);
        await openExportMenu(wrapper);
        expect(wrapper.get('[role="menu"]').text()).toContain("調査がない日のAmazon取得結果を出力します");
        await wrapper.get('[data-testid="inventory-trend-export"]').trigger("click");
        await flushPromises();

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
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

        await openExportMenu(wrapper);
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
        }, "API-ONLY");
        await flushPromises();

        expect(wrapper.get('[data-testid="survey-scope-note"]').text()).toContain("確定した倉庫だけを積み上げ");
        await wrapper.get('[data-testid="inventory-scope-select"] select').setValue("amazon");
        await flushPromises();

        expect(wrapper.find('[data-testid="amazon-daily-sources"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain("Amazon在庫の取得元");
        await openExportMenu(wrapper);
        expect(wrapper.get('[data-testid="inventory-trend-export"]').attributes("disabled")).toBeUndefined();
        await wrapper.get('[data-testid="inventory-trend-export"]').trigger("click");
        await flushPromises();
        expect(apiMocks.exportInventoryTrend).toHaveBeenCalledWith({ productCode: "API-ONLY", from: "2026-08-30", to: "2026-09-28" });
        expect(click).toHaveBeenCalledOnce();
        expect(wrapper.get('[data-summary-sku="API-ONLY-M"] [data-summary-field="latest"]').text()).toBe("7");
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

        expect(wrapper.get('[data-summary-sku="ZS-2408-01-NV-S"] [data-summary-field="latest"]').text()).not.toBe("8");
        refreshed = true;
        await wrapper.get('[data-testid="refresh-trend"]').trigger("click");
        await flushPromises();
        expect(wrapper.get('[data-summary-sku="ZS-2408-01-NV-S"] [data-summary-field="latest"]').text()).toBe("8");
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
        expect(wrapper.findAll('[data-testid="period-select"] [role="radio"]').map((option) => option.text())).toEqual(["2週間", "1か月", "3か月", "期間指定"]);
        expect(wrapper.get('[data-testid="period-select"] [aria-checked="true"]').text()).toBe("1か月");
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

    it("reloads the trend when the product of the route changes", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.setProps({ productCode: "ZS-2501-03" });
        await flushPromises();

        expect(wrapper.text()).toContain("ZS-2501-03");
        expect(wrapper.text()).toContain("ZS-2501-03-NT-F");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-stockout-point]")).toHaveLength(1);
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
        expect(firstSku.get('[data-summary-field="latest"]').text()).toBe("0");
        expect(firstSku.get('[data-summary-field="average"]').text()).toBe("24.3");
        expect(firstSku.get('[data-summary-field="minimum"]').text()).toBe("0");
        expect(firstSku.get('[data-summary-field="maximum"]').text()).toBe("42");
        expect(firstSku.get('[data-summary-field="stockout-days"]').text()).toBe("1");
        expect(firstSku.get('[data-summary-field="latest"] span').classes()).toContain("bg-hazard-pale");
        expect(firstSku.get('[data-summary-field="stockout-days"] span').classes()).toContain("bg-signal");
        expect(wrapper.text()).toContain("2026-08-30 → 09-28（30 日間）");
    });

    it("updates the daily chart when a custom target period is selected", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const startInput = wrapper.get('input[aria-label="対象期間の開始日"]');

        await startInput.setValue("2026-09-25");
        await flushPromises();

        expect(wrapper.get('[data-testid="period-select"] [aria-checked="true"]').text()).toBe("期間指定");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(4);
        expect(wrapper.findAll("[data-chart-date]").map((tick) => tick.text())).toEqual(["09-25", "26", "27", "28"]);
        expect(wrapper.findAll("[data-stockout-point]")).toHaveLength(0);
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ from: "2026-09-25", to: "2026-09-28" }));
    });

    it("limits a custom target period to 365 days", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('input[aria-label="対象期間の開始日"]').setValue("2025-01-01");
        await flushPromises();

        expect((wrapper.get('input[aria-label="対象期間の終了日"]').element as HTMLInputElement).value).toBe("2025-12-31");
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ from: "2025-01-01", to: "2025-12-31" }));
    });

    it("updates the target dates from the display period buttons", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="period-select"] [data-value="14"]').trigger("click");
        await flushPromises();

        expect((wrapper.get('input[aria-label="対象期間の開始日"]').element as HTMLInputElement).value).toBe("2026-09-15");
        expect((wrapper.get('input[aria-label="対象期間の終了日"]').element as HTMLInputElement).value).toBe("2026-09-28");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(14);
        expect(wrapper.findAll("[data-chart-date]")).toHaveLength(14);

        await wrapper.get('[data-testid="period-select"] [data-value="90"]').trigger("click");
        await flushPromises();
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ from: "2026-07-01", to: "2026-09-28" }));
        expect(wrapper.findAll("[data-chart-date]")).toHaveLength(6);

        const callCount = apiMocks.fetchInventoryTrend.mock.calls.length;
        await wrapper.get('[data-testid="period-select"] [data-value="custom"]').trigger("click");
        await flushPromises();
        expect(wrapper.get('[data-testid="period-select"] [aria-checked="true"]').text()).toBe("期間指定");
        expect(apiMocks.fetchInventoryTrend).toHaveBeenCalledTimes(callCount);
    });

    it("requests and displays the selected inventory scope", async () => {
        const wrapper = mountPage();
        await flushPromises();
        const inventoryScopeSelect = wrapper.get('[data-testid="inventory-scope-select"] select');

        await inventoryScopeSelect.setValue("boss");
        await flushPromises();

        expect(wrapper.findAll("[data-legend-warehouse]").map((item) => item.text())).toEqual(["BOSS"]);
        expect(wrapper.find('[data-testid="survey-scope-note"]').exists()).toBe(false);
        expect(apiMocks.fetchInventoryTrend).toHaveBeenLastCalledWith(expect.objectContaining({ scope: "boss" }));
    });

    it("uses the product chart and switches to the all-SKU chart grid", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="trend-view-product"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        expect(wrapper.findAll("[data-chart-bar-segment]")).toHaveLength(29);
        expect(wrapper.findAll('[data-testid="trend-view-product"], [data-testid="trend-view-sku"]').map((button) => button.text())).toEqual(["品番全体", "SKU別"]);
        expect(wrapper.find('[data-testid="chart-mode-bar"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="chart-mode-line"]').exists()).toBe(false);

        await wrapper.get('[data-testid="trend-view-sku"]').trigger("click");

        expect(wrapper.findAll("[data-sku-mini-chart]")).toHaveLength(4);
        expect(wrapper.findAll("[data-mini-chart-line]")).toHaveLength(4);
        expect(wrapper.find("[data-chart-bar]").exists()).toBe(false);

        await wrapper.get('[data-testid="trend-view-product"]').trigger("click");

        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
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

        expect(wrapper.text()).toContain("ZS-2408-01 の倉庫別在庫");
        expect(wrapper.text()).toContain("棒にマウスを置くと内訳を表示します");
        expect(wrapper.get('[data-testid="trend-view-product"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.findAll("[data-chart-bar]")).toHaveLength(30);
        const stockoutMarks = wrapper.findAll("[data-stockout-point]");
        expect(stockoutMarks).toHaveLength(1);
        expect(stockoutMarks[0]!.attributes("data-stockout-count")).toBe("4");

        await wrapper.findAll("[data-chart-bar]")[29]!.trigger("mouseenter");

        const tooltip = wrapper.get("[data-chart-tooltip]");
        expect(wrapper.find("[data-chart-highlight]").exists()).toBe(true);
        expect(tooltip.get("[data-tooltip-total]").text()).toContain("総在庫数0点");
        expect(tooltip.get("[data-tooltip-stockout]").text()).toContain("欠品SKU数4SKU");
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
        expect(wrapper.find("[data-legend-pending]").exists()).toBe(true);

        await wrapper.get('[data-bar-pending="true"]').trigger("mouseenter");
        expect(wrapper.get("[data-tooltip-total]").text()).toContain("未確定");
        expect(wrapper.get("[data-tooltip-total]").text()).not.toContain("点");
        expect(wrapper.get("[data-tooltip-stockout]").text()).toBe("欠品SKU数—");
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
        expect(wrapper.findAll("[data-chart-bar-pending]")).toHaveLength(0);
        expect(wrapper.findAll("[data-stockout-point]")).toHaveLength(1);
    });

    it("sparks each SKU trend in the summary table and ends at a yellow point when the latest stock is zero", async () => {
        const wrapper = mountPage();
        await flushPromises();

        const sparklines = wrapper.findAll('[data-summary-field="trend"] [data-sku-sparkline]');
        expect(sparklines).toHaveLength(4);
        const end = sparklines[0]!.findAll("circle").at(-1)!;
        expect(end.attributes("fill")).toBe("#f5c400");
        expect(end.attributes("stroke")).toBe("#11161a");
    });
});
