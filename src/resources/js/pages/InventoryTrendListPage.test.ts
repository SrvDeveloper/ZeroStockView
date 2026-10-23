import { flushPromises, mount, RouterLinkStub } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { InventoryTrendOverview, InventoryTrendOverviewProduct } from "@/api/inventoryTrends";
import InventoryTrendListPage from "@/pages/InventoryTrendListPage.vue";
import { useInventoryTrendListStore } from "@/stores/inventoryTrendList";

const apiMocks = vi.hoisted(() => ({ fetchInventoryTrendOverview: vi.fn(), exportInventoryTrend: vi.fn() }));
const routerMocks = vi.hoisted(() => ({ route: { query: {} as Record<string, string> }, replace: vi.fn() }));

vi.mock("@/api/inventoryTrends", () => apiMocks);
vi.mock("vue-router", () => ({ useRoute: () => routerMocks.route, useRouter: () => ({ replace: routerMocks.replace }) }));

function product(overrides: Partial<InventoryTrendOverviewProduct> = {}): InventoryTrendOverviewProduct {
    return {
        productCode: "ZS-1000",
        brand: "ALPHA",
        category: "アウター",
        skuCount: 4,
        groups: [
            { key: "setting:3", name: "売上TOP20" },
            { key: "setting:5", name: "秋冬新作" },
        ],
        latest: { date: "2026-10-23", quantity: 143 },
        weekAgo: { date: "2026-10-16", quantity: 199 },
        points: [
            { date: "2026-10-22", quantity: 150 },
            { date: "2026-10-23", quantity: 143 },
        ],
        skuStates: ["inStock", "stockout", "pending", "stockout"],
        stockoutSkuCount: 2,
        stockoutSkuCountWeekAgo: 1,
        stockoutSkuDays: 43,
        longestStockoutStreak: 26,
        ...overrides,
    };
}

function overview(overrides: Partial<InventoryTrendOverview> = {}): InventoryTrendOverview {
    return {
        data: [
            product(),
            product({
                productCode: "ZS-1012",
                groups: [{ key: "ungrouped", name: "グループなし" }],
                latest: { date: "2026-10-23", quantity: 100 },
                weekAgo: { date: "2026-10-16", quantity: 96 },
                skuStates: ["inStock", "inStock"],
                skuCount: 2,
                stockoutSkuCount: 0,
                stockoutSkuCountWeekAgo: 0,
                stockoutSkuDays: 0,
                longestStockoutStreak: null,
            }),
        ],
        summary: {
            productCount: 326,
            skuCount: 2184,
            stockoutSkuCount: 148,
            stockoutSkuCountWeekAgo: 136,
            longStockoutSkuCount: 37,
            longStockoutProductCount: 21,
            totalQuantity: 48210,
            totalQuantityWeekAgo: 49803,
            focusCounts: { all: 326, stockout: 58, increased: 9, decreasing: 14, long: 21 },
        },
        latestSurvey: { date: "2026-10-23", amazonInventoryStatus: "complete", amazonBasisAt: "2026-10-23T09:42:00+09:00" },
        meta: { page: 1, perPage: 25, total: 326 },
        from: "2026-09-24",
        to: "2026-10-23",
        brands: ["ALPHA", "BRAVO"],
        groups: [
            { key: "setting:3", name: "売上TOP20" },
            { key: "ungrouped", name: "グループなし" },
        ],
        dateRange: { from: "2026-01-01", to: "2026-10-23" },
        ...overrides,
    };
}

function mountPage() {
    return mount(InventoryTrendListPage, { global: { stubs: { RouterLink: RouterLinkStub } } });
}

function lastParams() {
    return apiMocks.fetchInventoryTrendOverview.mock.lastCall![0];
}

describe("InventoryTrendListPage", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers({ toFake: ["Date"] });
        vi.setSystemTime(new Date("2026-10-23T03:00:00Z"));
        setActivePinia(createPinia());
        routerMocks.route.query = {};
        apiMocks.fetchInventoryTrendOverview.mockResolvedValue(overview());
        apiMocks.exportInventoryTrend.mockResolvedValue(new Blob(["csv"]));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("loads the last month with the default conditions and shows the overall numbers", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(apiMocks.fetchInventoryTrendOverview).toHaveBeenCalledOnce();
        expect(lastParams()).toEqual({ from: "2026-09-24", to: "2026-10-23", scope: "mallTotal", group: null, brand: null, search: "", focus: null, sort: null, page: 1 });
        const summary = wrapper.get('[data-testid="overview-summary"]').text();
        expect(summary).toContain("対象品番326品番");
        expect(summary).toContain("2,184 SKU");
        expect(summary).toContain("欠品SKU（10/23（金）時点）148SKU");
        expect(summary).toContain("前週から +12 SKU");
        expect(summary).toContain("30日以上続いている欠品37SKU");
        expect(summary).toContain("21品番");
        expect(summary).toContain("在庫の合計（モール全体）48,210点");
        expect(summary).toContain("前週比 ▼ 3.2%");
        expect(wrapper.get('[data-testid="latest-survey-status"]').text()).toContain("最新の調査 10/23（金）");
        expect(wrapper.get('[data-testid="latest-survey-status"]').text()).toContain("Amazon 取込済み 09:42");
        expect(wrapper.findAll("[data-focus]").map((button) => button.text())).toEqual(["すべて 326", "欠品SKUあり 58", "欠品が増えた 9", "在庫が10%以上減った 14", "30日以上の欠品 21"]);
    });

    it("reloads the period up to the latest survey date when it is before today", async () => {
        apiMocks.fetchInventoryTrendOverview.mockResolvedValueOnce(overview({ dateRange: { from: "2026-01-01", to: "2026-10-20" } }));
        mountPage();
        await flushPromises();

        expect(apiMocks.fetchInventoryTrendOverview).toHaveBeenCalledTimes(2);
        expect(lastParams()).toEqual(expect.objectContaining({ from: "2026-09-21", to: "2026-10-20" }));
    });

    it("shows an unconfirmed total instead of a partial sum", async () => {
        apiMocks.fetchInventoryTrendOverview.mockResolvedValue(overview({ summary: { ...overview().summary, totalQuantity: null } }));
        const wrapper = mountPage();
        await flushPromises();

        const summary = wrapper.get('[data-testid="overview-summary"]').text();
        expect(summary).toContain("在庫の合計（モール全体）—点");
        expect(summary).toContain("未確定の倉庫があります");
    });

    it("shows each product row with the week-over-week change, SKU states, and stockouts", async () => {
        const wrapper = mountPage();
        await flushPromises();

        const first = wrapper.get('[data-overview-product="ZS-1000"]');
        expect(first.text()).toContain("ALPHA · アウター · 4 SKU");
        // 複数のグループに入っていても、先頭のグループと残りの数だけを出す
        expect(first.findAll("[data-overview-group]")).toHaveLength(1);
        expect(first.get("[data-overview-group]").text()).toBe("売上TOP20");
        expect(first.get("[data-overview-group-more]").text()).toBe("+1");
        expect(first.get("[data-overview-group-more]").attributes("aria-label")).toBe("ほか1グループ");
        expect(first.get("[data-overview-group]").element.parentElement?.getAttribute("title")).toBe("売上TOP20、秋冬新作");
        expect(first.text()).not.toContain("秋冬新作");
        expect(first.get('[data-overview-field="latest"]').text()).toBe("143");
        expect(first.get('[data-overview-field="delta"]').text()).toBe("▼ 28.1%");
        expect(first.find('[data-overview-field="delta"] [data-strong="true"]').exists()).toBe(true);
        expect(first.findAll("[data-sku-state]").map((cell) => cell.attributes("data-sku-state"))).toEqual(["inStock", "stockout", "pending", "stockout"]);
        expect(first.get("[data-sku-state-strip]").attributes("aria-label")).toBe("4SKUのうち欠品 2、未確定 1");
        expect(first.get('[data-overview-field="stockout"]').text()).toContain("2 / 4");
        expect(first.get('[data-overview-field="stockout"]').text()).toContain("前週から +1");
        expect(first.get('[data-overview-field="stockout-days"]').text()).toBe("43");
        expect(first.find("[data-sku-sparkline]").exists()).toBe(true);

        const second = wrapper.get('[data-overview-product="ZS-1012"]');
        expect(second.get('[data-overview-field="delta"]').text()).toBe("▲ 4.2%");
        expect(second.find('[data-overview-field="delta"] [data-strong="true"]').exists()).toBe(false);
        expect(second.text()).toContain("グループなし");
        expect(second.find("[data-overview-group-more]").exists()).toBe(false);
        expect(second.get('[data-overview-field="stockout"]').text()).not.toContain("前週から");
    });

    it("shows a dash when the week-over-week change cannot be compared", async () => {
        apiMocks.fetchInventoryTrendOverview.mockResolvedValue(
            overview({ data: [product({ weekAgo: null }), product({ productCode: "ZS-2", latest: { date: "2026-10-23", quantity: 50 }, weekAgo: { date: "2026-10-16", quantity: 50 } })] }),
        );
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-overview-product="ZS-1000"] [data-overview-field="delta"]').text()).toBe("—");
        expect(wrapper.get('[data-overview-product="ZS-2"] [data-overview-field="delta"]').text()).toBe("±0.0%");
    });

    it("opens the detail with the list conditions and remembers the order of the page", async () => {
        routerMocks.route.query = { scope: "boss", focus: "stockout" };
        const wrapper = mountPage();
        await flushPromises();

        const link = wrapper.get('[data-overview-product="ZS-1000"]').findComponent(RouterLinkStub);
        expect(link.props("to")).toEqual({ name: "inventory-trend", params: { productCode: "ZS-1000" }, query: { scope: "boss", focus: "stockout" } });
        expect(useInventoryTrendListStore().productCodes).toEqual(["ZS-1000", "ZS-1012"]);
        expect(useInventoryTrendListStore().query).toEqual({ scope: "boss", focus: "stockout" });
    });

    it("filters by the focus buttons and keeps the condition in the URL", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-focus="decreasing"]').trigger("click");
        await flushPromises();

        expect(lastParams()).toEqual(expect.objectContaining({ focus: "decreasing", page: 1 }));
        expect(routerMocks.replace).toHaveBeenLastCalledWith({ query: { focus: "decreasing" } });
        expect(wrapper.get('[data-focus="decreasing"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.get('[data-focus="all"]').attributes("aria-pressed")).toBe("false");
    });

    it("sorts by the column headers", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-sort="stockout"]').element.closest("th")!.getAttribute("aria-sort")).toBe("descending");
        await wrapper.get('[data-sort="delta"]').trigger("click");
        await flushPromises();

        expect(lastParams()).toEqual(expect.objectContaining({ sort: "delta" }));
        expect(routerMocks.replace).toHaveBeenLastCalledWith({ query: { sort: "delta" } });
        expect(wrapper.get('[data-sort="delta"]').element.closest("th")!.getAttribute("aria-sort")).toBe("ascending");
        expect(wrapper.get('[data-sort="stockout"]').element.closest("th")!.getAttribute("aria-sort")).toBe("none");
    });

    it("filters by group, brand, and a search that waits for the typing to stop", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="group-filter"] select').setValue("ungrouped");
        await flushPromises();
        expect(lastParams()).toEqual(expect.objectContaining({ group: "ungrouped" }));
        await wrapper.get('[data-testid="brand-filter"] select').setValue("BRAVO");
        await flushPromises();
        expect(lastParams()).toEqual(expect.objectContaining({ group: "ungrouped", brand: "BRAVO" }));

        vi.useFakeTimers({ toFake: ["Date", "setTimeout", "clearTimeout"] });
        const calls = apiMocks.fetchInventoryTrendOverview.mock.calls.length;
        await wrapper.get('[data-testid="product-search"] input').setValue("SEARCH");
        vi.advanceTimersByTime(299);
        expect(apiMocks.fetchInventoryTrendOverview).toHaveBeenCalledTimes(calls);
        vi.advanceTimersByTime(1);
        await flushPromises();
        expect(lastParams()).toEqual(expect.objectContaining({ search: "SEARCH", page: 1 }));
        expect(routerMocks.replace).toHaveBeenLastCalledWith({ query: { group: "ungrouped", brand: "BRAVO", search: "SEARCH" } });
    });

    it("restores every condition from the URL", async () => {
        routerMocks.route.query = { scope: "boss", period: "custom", from: "2026-09-01", to: "2026-09-10", group: "setting:3", brand: "ALPHA", search: "ZS", focus: "long", sort: "code", page: "2" };
        mountPage();
        await flushPromises();

        expect(lastParams()).toEqual({ from: "2026-09-01", to: "2026-09-10", scope: "boss", group: "setting:3", brand: "ALPHA", search: "ZS", focus: "long", sort: "code", page: 2 });
    });

    it("moves between pages", async () => {
        apiMocks.fetchInventoryTrendOverview.mockResolvedValue(overview({ meta: { page: 1, perPage: 25, total: 60 } }));
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('nav[aria-label="ページ切替"] button[aria-label="次のページ"]').trigger("click");
        await flushPromises();

        expect(lastParams()).toEqual(expect.objectContaining({ page: 2 }));
        expect(routerMocks.replace).toHaveBeenLastCalledWith({ query: { page: "2" } });
    });

    it("changes the scope and the period", async () => {
        const wrapper = mountPage();
        await flushPromises();

        const amazon = wrapper.findAll('[data-testid="inventory-scope-select"] [role="radio"]').find((option) => option.text() === "Amazon")!;
        await amazon.trigger("click");
        await flushPromises();
        expect(lastParams()).toEqual(expect.objectContaining({ scope: "amazon" }));
        expect(wrapper.get('[data-testid="overview-summary"]').text()).toContain("在庫の合計（Amazon）");

        const twoWeeks = wrapper.findAll('[data-testid="period-select"] [role="radio"]').find((option) => option.text() === "2週間")!;
        await twoWeeks.trigger("click");
        await flushPromises();
        expect(lastParams()).toEqual(expect.objectContaining({ from: "2026-10-10", to: "2026-10-23" }));
        expect(routerMocks.replace).toHaveBeenLastCalledWith({ query: { scope: "amazon", period: "14" } });
    });

    it("shows a retry button when the list cannot be loaded and explains empty results", async () => {
        apiMocks.fetchInventoryTrendOverview.mockRejectedValueOnce(new Error("failed"));
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain("在庫推移の一覧を読み込めませんでした");
        apiMocks.fetchInventoryTrendOverview.mockResolvedValueOnce(overview({ data: [] }));
        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("再試行"))!
            .trigger("click");
        await flushPromises();
        expect(wrapper.text()).toContain("在庫推移のある品番がありません");

        apiMocks.fetchInventoryTrendOverview.mockResolvedValueOnce(overview({ data: [] }));
        await wrapper.get('[data-focus="long"]').trigger("click");
        await flushPromises();
        expect(wrapper.text()).toContain("条件に合う品番がありません");
    });

    it("exports the daily CSV of all products for the period", async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="inventory-trend-export-button"]').trigger("click");
        await wrapper.get('[data-testid="inventory-trend-export-all"]').trigger("click");
        await flushPromises();

        expect(apiMocks.exportInventoryTrend).toHaveBeenCalledWith({ from: "2026-09-24", to: "2026-10-23" });
        expect(click).toHaveBeenCalledOnce();
    });
});
