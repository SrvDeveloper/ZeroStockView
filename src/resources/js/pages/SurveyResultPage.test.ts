import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";
import SurveyResultPage from "@/pages/SurveyResultPage.vue";
import { useAuthStore } from "@/stores/auth";
import { useSurveysStore } from "@/stores/surveys";
import type { Survey, SurveyProductResult } from "@/types";
import { formatShortDateTime } from "@/utils/format";
import { toSurveySummary } from "@/utils/stock";

vi.mock("vue-router", () => ({ useRouter: () => ({ push: vi.fn() }) }));

function product(code: string, freeStock: number): SurveyProductResult {
    return {
        id: `p-${code}`,
        productCode: code,
        brand: "",
        category: "",
        parentAsin: "",
        memo: "",
        skus: [{ id: `s-${code}`, skuCode: `${code}-M`, size: "M", asin: "B1", memo: "", stock: { amazonOwn: 1, amazonFba: 1, bossOwn: 1, bossRfc: 0, freeStock, ecStock: 1 } }],
    };
}

function survey(status: Survey["amazonInventoryStatus"], products = [product("A", 1), product("B", 1)]): Survey {
    return { id: "1", executedAt: "2026-10-16T00:00:00Z", importSettingName: null, includesAmazonInventory: false, amazonSource: "sp_api", amazonInventoryStatus: status, files: [], products };
}

/** 品番カードが描かれた回数（品番コードごと）。v-memo が効いていれば、中身が同じカードは描き直されない */
const cardRenders = new Map<string, number>();
const CountingCard = defineComponent({
    inheritAttrs: false,
    setup(_, { slots, attrs }) {
        return () => {
            const content = slots.default?.();
            if (String(attrs.class ?? "").includes("survey-product-card")) {
                const code = String(attrs["data-product-code"] ?? "");
                cardRenders.set(code, (cardRenders.get(code) ?? 0) + 1);
            }
            return h("div", attrs, content);
        };
    },
});

const mounted: { unmount: () => void }[] = [];

function open(detail: Survey) {
    const store = useSurveysStore();
    store.summaries = [toSurveySummary(detail)];
    store.details = { [detail.id]: detail };
    vi.spyOn(store, "loadSurvey").mockImplementation(async (id: string) => store.details[id]!);
    const wrapper = mount(SurveyResultPage, { props: { id: detail.id }, global: { stubs: { RouterLink: true, BaseCard: CountingCard } } });
    mounted.push(wrapper);
    return { store, wrapper };
}

function setVisibility(state: "visible" | "hidden") {
    Object.defineProperty(document, "visibilityState", { configurable: true, get: () => state });
    document.dispatchEvent(new Event("visibilitychange"));
}

describe("SurveyResultPage refreshing", () => {
    let intervals: Map<number, () => void>;
    let nextTimer: number;

    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "viewer", role: "viewer" };
        cardRenders.clear();
        intervals = new Map();
        nextTimer = 1;
        // 30秒ごとの再取得の timer だけを数える（ほかの部品の timer は対象外）
        vi.spyOn(window, "setInterval").mockImplementation((handler, timeout) => {
            const id = nextTimer++;
            if (timeout === 30000) intervals.set(id, handler as () => void);
            return id;
        });
        vi.spyOn(window, "clearInterval").mockImplementation((id) => void intervals.delete(id as number));
        setVisibility("visible");
    });

    afterEach(() => {
        mounted.splice(0).forEach((wrapper) => wrapper.unmount());
        vi.restoreAllMocks();
        setVisibility("visible");
    });

    it("checks every 30 seconds only while the Amazon stock is pending or partial", async () => {
        const completed = open(survey("complete")).wrapper;
        await flushPromises();
        expect(intervals.size).toBe(0);
        completed.unmount();

        const { store } = open(survey("partial"));
        await flushPromises();
        expect(intervals.size).toBe(1);
        expect(window.setInterval).toHaveBeenLastCalledWith(expect.any(Function), 30000);

        store.details["1"]!.amazonInventoryStatus = "complete";
        await flushPromises();
        expect(intervals.size).toBe(0);
    });

    it("pauses while the page is hidden and checks right away when it is shown again", async () => {
        const { store } = open(survey("pending"));
        await flushPromises();
        const refresh = vi.spyOn(store, "refreshSurvey").mockImplementation(async () => store.details["1"]!);
        expect(intervals.size).toBe(1);

        setVisibility("hidden");
        await flushPromises();
        expect(intervals.size).toBe(0);

        setVisibility("visible");
        await flushPromises();
        expect(refresh).toHaveBeenCalledOnce();
        expect(intervals.size).toBe(1);
    });

    it("redraws only the product cards whose content changed after a refresh", async () => {
        const { store, wrapper } = open(survey("pending"));
        await flushPromises();
        const before = { ...Object.fromEntries(cardRenders) };
        expect(Object.keys(before).sort()).toEqual(["A", "B"]);

        // 同じ内容の明細を取り直しても（別のオブジェクトでも）、カードは描き直さない
        store.storeDetail(survey("pending"));
        await flushPromises();
        expect(Object.fromEntries(cardRenders)).toEqual(before);

        // 品番Bの在庫だけが変わったら、Bのカードだけ描き直す
        store.storeDetail(survey("pending", [product("A", 1), product("B", 0)]));
        await flushPromises();
        expect(cardRenders.get("A")).toBe(before.A);
        expect(cardRenders.get("B")).toBe(before.B! + 1);
        expect(wrapper.text()).toContain("B-M");
    });
});

describe("SurveyResultPage BOSS 在庫の再現", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "viewer", role: "viewer" };
    });

    afterEach(() => {
        mounted.splice(0).forEach((wrapper) => wrapper.unmount());
        vi.restoreAllMocks();
    });

    it("shows the basis time and marks the BOSS column only for a survey reconstructed from the history", async () => {
        const { wrapper } = open({ ...survey("complete"), bossSource: "history", bossBasisAt: "2026-10-17T00:00:00Z" });
        await flushPromises();

        expect(wrapper.get("[data-boss-history-basis]").text()).toContain(`BOSS: 履歴から再現（${formatShortDateTime("2026-10-17T00:00:00Z")}時点）`);
        const mark = wrapper.get("[data-boss-history-mark]");
        expect(mark.attributes("title")).toBe("在庫変動履歴から再現した値です");
        // 数値の表示は変えない
        expect(wrapper.get('[data-stock-field="boss"]').text()).toContain("1");
    });

    it("shows neither for a survey imported from the BOSS report", async () => {
        const { wrapper } = open({ ...survey("complete"), bossSource: "report", bossBasisAt: null });
        await flushPromises();

        expect(wrapper.find("[data-boss-history-basis]").exists()).toBe(false);
        expect(wrapper.find("[data-boss-history-mark]").exists()).toBe(false);
    });
});
