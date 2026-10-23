import { flushPromises, mount, RouterLinkStub } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { reactive } from "vue";
import InventoryTrendListPage from "@/pages/InventoryTrendListPage.vue";

const apiMocks = vi.hoisted(() => ({ fetchInventoryTrendProducts: vi.fn() }));
const route = vi.hoisted(() => ({ query: {} as Record<string, string> }));

vi.mock("@/api/inventoryTrends", () => apiMocks);
vi.mock("vue-router", () => ({ useRoute: () => route }));

function mountPage() {
    return mount(InventoryTrendListPage, { global: { stubs: { RouterLink: RouterLinkStub } } });
}

describe("InventoryTrendListPage", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.assign(route, reactive({ query: {} }));
        apiMocks.fetchInventoryTrendProducts.mockResolvedValue({
            data: [
                { productCode: "ZS-2501-03", brand: "FIELD NOTE", category: "バッグ", skuCount: 3, hasSurveyHistory: true },
                { productCode: "ZS-2408-01", brand: "NORTH HARBOR", category: "トップス", skuCount: 4, hasSurveyHistory: true },
            ],
            brands: ["FIELD NOTE", "NORTH HARBOR"],
            dateRange: { from: "2026-01-01", to: "2026-09-28" },
        });
    });

    it("lists the products in code order and opens the detail of each product", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.findAll("[data-product-code]").map((item) => item.attributes("data-product-code"))).toEqual(["ZS-2408-01", "ZS-2501-03"]);
        expect(wrapper.get('[data-product-code="ZS-2408-01"]').text()).toContain("NORTH HARBOR ・ トップス ・ 4 SKU");
        expect(wrapper.findAllComponents(RouterLinkStub)[0]!.props("to")).toEqual({ name: "inventory-trend", params: { productCode: "ZS-2408-01" }, query: {} });
    });

    it("passes the scope and period of the list to the detail", async () => {
        route.query = { scope: "boss", period: "custom", from: "2026-09-01", to: "2026-09-10", page: "2" };
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.findAllComponents(RouterLinkStub)[0]!.props("to")).toEqual({
            name: "inventory-trend",
            params: { productCode: "ZS-2408-01" },
            query: { scope: "boss", period: "custom", from: "2026-09-01", to: "2026-09-10" },
        });
    });

    it("shows a retry button when the products cannot be loaded", async () => {
        apiMocks.fetchInventoryTrendProducts.mockRejectedValueOnce(new Error("failed"));
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain("品番の一覧を読み込めませんでした");
        await wrapper.get("button").trigger("click");
        await flushPromises();
        expect(apiMocks.fetchInventoryTrendProducts).toHaveBeenCalledTimes(2);
        expect(wrapper.findAll("[data-product-code]")).toHaveLength(2);
    });

    it("explains when no product has a survey", async () => {
        apiMocks.fetchInventoryTrendProducts.mockResolvedValueOnce({ data: [], brands: [], dateRange: null });
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain("在庫推移のある品番がありません");
    });
});
