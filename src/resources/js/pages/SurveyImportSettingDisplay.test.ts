import { flushPromises, mount } from "@vue/test-utils";
import axios from "axios";
import { nextTick } from "vue";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SurveyHistoryPage from "@/pages/SurveyHistoryPage.vue";
import MemoField from "@/components/ui/MemoField.vue";
import SurveyResultPage from "@/pages/SurveyResultPage.vue";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import type { Survey } from "@/types";
import { toSurveySummary } from "@/utils/stock";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({
    useRouter: () => ({ push }),
}));

/** サーバーにある調査（明細）。画面を開く直前に、一覧の概要と明細としてストアへ入れる（seedStore） */
let fixture: Survey[] = [];

/** 一覧は概要、開いた調査は明細を持たせる。結果画面の明細取得は、サーバーの代わりに fixture から返す */
function seedStore() {
    const surveys = useSurveysStore();
    surveys.summaries = fixture.map(toSurveySummary);
    surveys.details = Object.fromEntries(fixture.map((survey) => [survey.id, survey]));
    vi.spyOn(surveys, "loadSurvey").mockImplementation(async (id: string) => surveys.details[id]!);
    return surveys;
}

function mountSeeded<T extends Parameters<typeof mount>[0]>(component: T, options?: Parameters<typeof mount>[1]) {
    seedStore();
    return mount(component, options);
}

function seedSurvey() {
    const surveys = useSurveysStore();
    fixture = [
        {
            id: "1",
            executedAt: "2026-09-16T01:00:00Z",
            createdAt: "2026-09-18T03:00:00Z",
            importSettingName: "売上TOP20",
            products: [],
            files: [],
        },
    ];

    return surveys;
}

async function mountHistoryList() {
    const wrapper = mountSeeded(SurveyHistoryPage);
    await wrapper.get('[data-history-view] [data-value="list"]').trigger("click");
    return wrapper;
}

describe("survey result detail loading", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "viewer", role: "viewer" };
    });

    it("fetches the detail when the result URL is opened directly and compares with the previous summary", async () => {
        const surveys = useSurveysStore();
        const previous = { id: "0", executedAt: "2026-09-15T01:00:00Z", importSettingName: null, files: [], productCount: 1, skuCount: 1, allZeroCount: 0, partialZeroCount: 0 };
        surveys.summaries = [previous];
        let resolve: ((value: unknown) => void) | undefined;
        const get = vi.spyOn(axios, "get").mockImplementation(() => new Promise((done) => (resolve = done)));
        const wrapper = mount(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });

        expect(wrapper.find("[data-survey-loading]").exists()).toBe(true);
        expect(get).toHaveBeenCalledWith("/api/surveys/1");
        resolve?.({
            data: {
                data: {
                    id: "1",
                    executedAt: "2026-09-16T01:00:00Z",
                    importSettingName: null,
                    includesAmazonInventory: false,
                    amazonSource: "sp_api",
                    amazonInventoryStatus: "complete",
                    files: [],
                    products: [
                        {
                            id: "p",
                            productCode: "A-1001",
                            brand: "",
                            category: "",
                            parentAsin: "",
                            memo: "",
                            skus: [{ id: "s", skuCode: "A-1001-M", size: "M", asin: "", memo: "", stock: { amazonOwn: null, amazonFba: null, bossOwn: 0, bossRfc: 0, freeStock: 0, ecStock: 0 } }],
                        },
                    ],
                },
            },
        });
        await flushPromises();

        expect(wrapper.find("[data-survey-loading]").exists()).toBe(false);
        expect(wrapper.text()).toContain("A-1001");
        expect(wrapper.get('[data-summary="all-zero"]').text()).toContain("前回比 +1");
        expect(surveys.getSummary("1")?.allZeroCount).toBe(1);
        get.mockRestore();
    });

    it("tells a missing survey apart from a loading failure and retries", async () => {
        const get = vi.spyOn(axios, "get").mockRejectedValueOnce(Object.assign(new axios.AxiosError("not found"), { response: { status: 404 } }));
        const missing = mount(SurveyResultPage, { props: { id: "9" }, global: { stubs: { RouterLink: true } } });
        await flushPromises();
        expect(missing.text()).toContain("指定された調査結果が見つかりません");

        get.mockRejectedValueOnce(new Error("network")).mockResolvedValueOnce({ data: { data: { id: "8", executedAt: "2026-09-16T01:00:00Z", importSettingName: null, files: [], products: [] } } });
        const failed = mount(SurveyResultPage, { props: { id: "8" }, global: { stubs: { RouterLink: true } } });
        await flushPromises();
        expect(failed.text()).toContain("調査結果を読み込めませんでした");
        await failed
            .findAll("button")
            .find((button) => button.text().includes("再試行"))!
            .trigger("click");
        await flushPromises();
        expect(failed.text()).toContain("在庫調査結果");
        get.mockRestore();
    });
});

describe("survey import setting display", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
        push.mockReset();
        seedSurvey();
    });

    it("shows survey history without a product group column or filter", async () => {
        const wrapper = await mountHistoryList();

        expect(wrapper.text()).not.toContain("品番グループ");
        expect(wrapper.text()).not.toContain("売上TOP20");
        expect(wrapper.find("[data-testid=import-setting-filter]").exists()).toBe(false);
        expect(wrapper.text()).toContain("初回取込日時");
        expect(wrapper.text()).toContain("結果作成: 2026/09/18 03:00");
    });

    it("filters survey history by period", async () => {
        fixture = [
            { id: "1", executedAt: "2026-09-18T12:00:00Z", importSettingName: "売上TOP20", products: [], files: [] },
            { id: "2", executedAt: "2026-09-17T12:00:00Z", importSettingName: "売上TOP20", products: [], files: [] },
            { id: "3", executedAt: "2026-09-10T12:00:00Z", importSettingName: "サングラス", products: [], files: [] },
            { id: "4", executedAt: "2026-09-17T08:00:00Z", importSettingName: null, products: [], files: [] },
        ];
        const wrapper = await mountHistoryList();
        const displayedIds = () => wrapper.findAll("[data-survey-id]").map((row) => row.attributes("data-survey-id"));

        expect(displayedIds()).toEqual(["1", "2", "4", "3"]);

        expect(wrapper.find("[data-testid=date-filter-mode]").exists()).toBe(false);
        expect(wrapper.find("[data-testid=survey-date-filter]").exists()).toBe(false);

        await wrapper.get("[data-testid=survey-date-from]").setValue("2026-09-17");
        await wrapper.get("[data-testid=survey-date-to]").setValue("2026-09-17");
        expect(displayedIds()).toEqual(["2", "4"]);
        expect(wrapper.text()).toContain("全4件中2件を表示");

        await wrapper.get("[data-testid=survey-date-from]").setValue("2026-09-10");
        expect(displayedIds()).toEqual(["2", "4", "3"]);

        await wrapper.get("[data-testid=survey-date-from]").setValue("2026-09-18");
        expect(wrapper.text()).toContain("開始日は終了日以前の日付を指定してください");
        expect(wrapper.text()).toContain("条件に一致する調査履歴がありません");

        await wrapper.get("[data-testid=reset-filters]").trigger("click");
        expect(displayedIds()).toEqual(["1", "2", "4", "3"]);
    });

    it("keeps a rechecked past result out of the latest position", async () => {
        fixture = [
            { id: "current", executedAt: "2026-09-18T12:00:00Z", createdAt: "2026-09-18T12:00:00Z", importSettingName: "売上TOP20", products: [], files: [] },
            { id: "rechecked", executedAt: "2026-09-16T12:00:00Z", createdAt: "2026-09-30T12:00:00Z", importSettingName: "別設定", products: [], files: [] },
        ];
        const wrapper = await mountHistoryList();

        expect(wrapper.get("[data-survey-id=current]").text()).toContain("最新");
        expect(wrapper.get("[data-survey-id=rechecked]").text()).not.toContain("最新");
    });

    it("shows the survey dates without listing the product groups", () => {
        const wrapper = mountSeeded(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });

        expect(wrapper.text()).not.toContain("品番グループ:");
        expect(wrapper.text()).toContain("初回取込日時: 2026/09/16 01:00");
        expect(wrapper.text()).not.toContain("在庫基準日時");
        expect(wrapper.text()).toContain("結果作成日時: 2026/09/18 03:00");
    });

    it("shows all products in one daily history and lets the result filter by group", async () => {
        const baseSku = {
            id: "sku-a",
            skuCode: "A-1001-M",
            colorName: "",
            size: "M",
            asin: "",
            tqCode: "A1001",
            tqColorNo: "01",
            tqSize: "M",
            memo: "",
            stock: { amazonOwn: null, amazonFba: null, bossOwn: 4, bossRfc: 0, freeStock: null, ecStock: null },
        };
        fixture[0].groups = [
            { key: "setting:1", name: "Aグループ" },
            { key: "setting:2", name: "Bグループ" },
        ];
        fixture[0].products = [
            { id: "product-a", productCode: "A-1001", brand: "A", category: "A", parentAsin: "", memo: "", groups: [{ key: "setting:1", name: "Aグループ", sortOrder: 1 }], skus: [baseSku] },
            {
                id: "product-b",
                productCode: "B-2001",
                brand: "B",
                category: "B",
                parentAsin: "",
                memo: "",
                groups: [{ key: "setting:2", name: "Bグループ", sortOrder: 1 }],
                skus: [{ ...baseSku, id: "sku-b", skuCode: "B-2001-M" }],
            },
        ];

        const history = await mountHistoryList();
        expect(history.get('[data-survey-id="1"]').text()).not.toContain("Aグループ");
        expect(history.get('[data-survey-id="1"]').findAll("td")[1]!.text()).toBe("2");
        expect(history.get('[data-survey-id="1"]').findAll("td")[2]!.text()).toBe("2");
        await history.get('[data-survey-id="1"] button').trigger("click");
        expect(push).toHaveBeenCalledWith({ name: "survey-result", params: { id: "1" } });

        const result = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });
        expect(result.text()).toContain("A-1001");
        expect(result.text()).toContain("B-2001");
        await result.get("select").setValue("setting:2");
        expect(result.text()).toContain("B-2001");
        expect(result.text()).not.toContain("A-1001");
    });

    it("clears each text filter from its clear button", async () => {
        const wrapper = mountSeeded(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });
        const filters = [
            { placeholder: "品番で絞込", label: "品番の絞込をクリア" },
            { placeholder: "SKUで絞込", label: "SKUの絞込をクリア" },
            { placeholder: "ASINで絞込", label: "ASINの絞込をクリア" },
        ];

        for (const filter of filters) {
            const input = wrapper.get(`input[placeholder="${filter.placeholder}"]`);
            await input.setValue("検索値");

            await wrapper.get(`button[aria-label="${filter.label}"]`).trigger("click");

            expect((input.element as HTMLInputElement).value).toBe("");
            expect(wrapper.find(`button[aria-label="${filter.label}"]`).exists()).toBe(false);
        }
    });

    it("shows combined Amazon and BOSS stock with Ctrl-hover breakdowns", async () => {
        fixture[0].products = [
            {
                id: "product-1",
                productCode: "A-1001",
                brand: "ブランドA",
                category: "カテゴリA",
                parentAsin: "PARENT-ASIN",
                memo: "",
                skus: [
                    {
                        id: "sku-1",
                        skuCode: "A-1001-01-M",
                        size: "M",
                        asin: "ASIN-1",
                        stock: { amazonOwn: 0, amazonFba: 3, bossOwn: 0, bossRfc: 0, freeStock: 5, ecStock: 6 },
                        memo: "",
                    },
                ],
            },
        ];
        const wrapper = mountSeeded(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });

        const amazonCell = wrapper.get("[data-stock-field=amazon]");
        const bossCell = wrapper.get("[data-stock-field=boss]");
        const totalStockCell = wrapper.get("[data-stock-field=total]");
        expect(wrapper.classes()).not.toContain("stock-breakdown-enabled");
        expect(wrapper.text()).not.toContain("Ctrlキー");

        window.dispatchEvent(new KeyboardEvent("keydown", { key: "Control" }));
        await nextTick();
        expect(wrapper.classes()).toContain("stock-breakdown-enabled");

        window.dispatchEvent(new KeyboardEvent("keyup", { key: "Control" }));
        await nextTick();
        expect(wrapper.classes()).not.toContain("stock-breakdown-enabled");
        window.dispatchEvent(new KeyboardEvent("keydown", { key: "Control" }));
        await nextTick();
        window.dispatchEvent(new Event("blur"));
        await nextTick();
        expect(wrapper.classes()).not.toContain("stock-breakdown-enabled");

        expect(wrapper.text()).not.toContain("合算表示");
        expect(wrapper.find("[data-stock-field=amazonOwn]").exists()).toBe(false);
        expect(wrapper.find("[data-stock-field=amazonFba]").exists()).toBe(false);
        expect(amazonCell.get("[data-stock-value]").text()).toBe("3");
        expect(amazonCell.attributes("data-stock-state")).toBe("low");
        expect(amazonCell.attributes("tabindex")).toBe("0");
        expect(amazonCell.get("[role=tooltip]").text()).toBe("自社 0／FBA 3");
        expect(amazonCell.find("[data-breakdown]").exists()).toBe(false);
        expect(bossCell.get("[data-stock-value]").text()).toBe("0");
        expect(bossCell.attributes("data-stock-state")).toBe("zero");
        expect(bossCell.get("[role=tooltip]").text()).toBe("自社 0／RFC 0");
        expect(wrapper.get("thead").text()).toContain("総数");
        expect(wrapper.get("thead").text()).not.toContain("自社／FBA");
        expect(wrapper.findAll("[role=tooltip]")).toHaveLength(2);
        expect(totalStockCell.text()).toBe("14");
        expect(totalStockCell.attributes("data-stock-state")).toBe("has");
        expect(wrapper.get("[data-stock-field=freeStock]").attributes("data-stock-state")).toBe("has");
        expect(wrapper.get("[data-stock-field=ecStock]").attributes("data-stock-state")).toBe("has");
        expect(wrapper.get("tbody tr").attributes("data-row-state")).toBeUndefined();
        await wrapper.get('input[placeholder="SKUで絞込"]').setValue("A-1001");
        expect(wrapper.get("tbody tr").attributes("data-row-state")).toBe("matched");
        expect(wrapper.get("[data-stock-field=amazon]").attributes("data-stock-state")).toBe("low");
        await wrapper.get('button[aria-label="SKUの絞込をクリア"]').trigger("click");
        const andOperator = wrapper.get('[data-condition-operator] [data-value="and"]');
        const orOperator = wrapper.get('[data-condition-operator] [data-value="or"]');
        expect(andOperator.attributes("aria-checked")).toBe("true");
        await wrapper.get('[data-stock-condition="bossZero"]').trigger("click");
        await wrapper.get('[data-stock-condition="ecZero"]').trigger("click");
        expect(wrapper.find("tbody tr").exists()).toBe(false);
        await orOperator.trigger("click");
        expect(orOperator.attributes("aria-checked")).toBe("true");
        expect(andOperator.attributes("aria-checked")).toBe("false");
        expect(wrapper.find("tbody tr").exists()).toBe(true);
        await wrapper.get("[data-clear-filters]").trigger("click");
        expect(andOperator.attributes("aria-checked")).toBe("true");
        await wrapper.get('[data-stock-condition="amazonHas"]').trigger("click");
        await wrapper.get('[data-stock-condition="bossHas"]').trigger("click");
        expect(wrapper.find("tbody tr").exists()).toBe(false);
        await orOperator.trigger("click");
        expect(wrapper.find("tbody tr").exists()).toBe(true);
    });

    it("shows the saved Amazon stock and a confirmed total for a three-file survey", () => {
        fixture[0].includesAmazonInventory = false;
        fixture[0].amazonSource = "sp_api";
        fixture[0].amazonInventoryStatus = "complete";
        fixture[0].amazonBasisAt = "2026-09-16T00:00:00Z";
        fixture[0].products = [
            {
                id: "product-1",
                productCode: "A-1001",
                brand: "ブランドA",
                category: "カテゴリA",
                parentAsin: "PARENT-ASIN",
                memo: "",
                skus: [
                    {
                        id: "sku-1",
                        skuCode: "A-1001-01-M",
                        size: "M",
                        asin: "ASIN-1",
                        stock: { amazonOwn: 2, amazonFba: 3, bossOwn: 4, bossRfc: 5, freeStock: 6, ecStock: 7 },
                        memo: "",
                    },
                ],
            },
        ];

        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });

        expect(wrapper.get("[data-stock-field=amazon] [data-stock-value]").text()).toBe("5");
        expect(wrapper.get("[data-stock-field=boss] [data-stock-value]").text()).toBe("9");
        expect(wrapper.get('[data-summary="amazon"]').text()).toContain("基準 00:00");
        expect(wrapper.text()).not.toContain("ほかの在庫とは取得時刻が異なる");
        expect(wrapper.text()).not.toContain("Amazon 在庫履歴はありません");
        expect(wrapper.get("[data-stock-field=total]").text()).toBe("27");
        expect(wrapper.get("thead").text()).toContain("総数");
    });

    it("keeps the total unknown until each SKU has Amazon stock", async () => {
        const surveys = useSurveysStore();
        fixture[0].includesAmazonInventory = false;
        fixture[0].amazonSource = "sp_api";
        fixture[0].amazonInventoryStatus = "pending";
        fixture[0].products = [
            {
                id: "product-1",
                productCode: "A-1001",
                brand: "ブランドA",
                category: "カテゴリA",
                parentAsin: "PARENT-ASIN",
                memo: "",
                skus: [
                    {
                        id: "sku-1",
                        skuCode: "A-1001-01-M",
                        size: "M",
                        asin: "ASIN-1",
                        stock: { amazonOwn: null, amazonFba: null, bossOwn: 4, bossRfc: 5, freeStock: 6, ecStock: 7 },
                        memo: "",
                    },
                ],
            },
        ];
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });

        expect(wrapper.text()).toContain("Amazon 在庫は取込待ちです");
        expect(wrapper.get("[data-stock-field=amazon] [data-stock-value]").text()).toBe("取込待ち");
        expect(wrapper.get("[data-stock-field=total]").text()).toBe("—");

        surveys.details["1"]!.amazonInventoryStatus = "partial";
        surveys.details["1"]!.amazonBasisAt = "2026-09-16T00:00:00Z";
        await nextTick();
        expect(wrapper.text()).toContain("一部SKU未取得");
        expect(wrapper.get("[data-stock-field=total]").text()).toBe("—");
    });

    it("refreshes an open result after Amazon inventory succeeds and accepts a later same-day value", async () => {
        const surveys = useSurveysStore();
        fixture[0].amazonSource = "sp_api";
        fixture[0].amazonInventoryStatus = "pending";
        fixture[0].products = [
            {
                id: "product-1",
                productCode: "A-1001",
                brand: "A",
                category: "A",
                parentAsin: "PARENT",
                memo: "",
                skus: [
                    {
                        id: "sku-1",
                        skuCode: "A-1001-M",
                        size: "M",
                        asin: "ASIN-1",
                        memo: "",
                        stock: { amazonOwn: null, amazonFba: null, bossOwn: 4, bossRfc: 0, freeStock: 0, ecStock: 0 },
                    },
                ],
            },
        ];
        const first = JSON.parse(JSON.stringify(fixture[0]));
        first.amazonInventoryStatus = "complete";
        first.amazonBasisAt = "2026-09-16T02:00:00Z";
        first.products[0].skus[0].stock.amazonOwn = 2;
        first.products[0].skus[0].stock.amazonFba = 3;
        const latest = JSON.parse(JSON.stringify(first));
        latest.amazonBasisAt = "2026-09-16T04:00:00Z";
        latest.products[0].skus[0].stock.amazonOwn = 0;
        latest.products[0].skus[0].stock.amazonFba = 0;
        const get = vi
            .spyOn(axios, "get")
            .mockResolvedValueOnce({ data: { data: first } })
            .mockResolvedValueOnce({ data: { data: latest } });
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });
        vi.mocked(surveys.loadSurvey).mockRestore();

        expect(wrapper.text()).toContain("取込待ち");
        expect(wrapper.get("[data-stock-field=total]").text()).toBe("—");
        await wrapper.get('[data-testid="refresh-survey"]').trigger("click");
        await flushPromises();
        expect(get).toHaveBeenCalledWith("/api/surveys/1");
        expect(wrapper.get("[data-stock-field=amazon] [data-stock-value]").text()).toBe("5");
        expect(wrapper.get("[data-stock-field=total]").text()).toBe("9");
        expect(wrapper.get('[data-summary="amazon"]').text()).toContain("基準 02:00");

        await wrapper.get('[data-testid="refresh-survey"]').trigger("click");
        await flushPromises();
        expect(wrapper.get("[data-stock-field=amazon] [data-stock-value]").text()).toBe("0");
        expect(wrapper.get("[data-stock-field=total]").text()).toBe("4");
        expect(wrapper.get('[data-summary="amazon"]').text()).toContain("基準 04:00");
        wrapper.unmount();
        get.mockRestore();
    });

    it("checks an open SP-API result periodically and stops checking after leaving", async () => {
        const surveys = useSurveysStore();
        fixture[0].amazonSource = "sp_api";
        fixture[0].amazonInventoryStatus = "pending";
        let poll: (() => void) | undefined;
        const interval = vi.spyOn(window, "setInterval").mockImplementation((handler) => {
            poll = handler as () => void;
            return 123;
        });
        const clear = vi.spyOn(window, "clearInterval").mockImplementation(() => undefined);
        const refresh = vi.spyOn(surveys, "refreshSurvey").mockImplementation(async () => {
            surveys.details["1"]!.amazonInventoryStatus = "complete";
            surveys.details["1"]!.amazonBasisAt = "2026-09-16T02:00:00Z";
            return surveys.details["1"]!;
        });
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });

        expect(interval).toHaveBeenCalledWith(expect.any(Function), 30000);
        poll?.();
        await flushPromises();
        expect(refresh).toHaveBeenCalledWith("1");
        expect(wrapper.get('[data-summary="amazon"]').text()).toContain("基準 02:00");
        wrapper.unmount();
        expect(clear).toHaveBeenCalledWith(123);
        interval.mockRestore();
        clear.mockRestore();
    });

    it("shows Amazon import states in survey history and refreshes the basis time", async () => {
        const surveys = useSurveysStore();
        fixture = [
            { id: "pending", executedAt: "2026-09-18T12:00:00Z", importSettingName: "A", amazonInventoryStatus: "pending", products: [], files: [] },
            { id: "partial", executedAt: "2026-09-17T12:00:00Z", importSettingName: "B", amazonInventoryStatus: "partial", products: [], files: [] },
            { id: "complete", executedAt: "2026-09-16T12:00:00Z", importSettingName: "C", amazonInventoryStatus: "complete", products: [], files: [] },
            { id: "legacy", executedAt: "2026-09-15T12:00:00Z", importSettingName: "D", amazonInventoryStatus: "legacy_file", products: [], files: [] },
        ];
        const wrapper = await mountHistoryList();

        expect(wrapper.get("[data-survey-id=pending]").text()).toContain("Amazon取込待ち");
        expect(wrapper.get("[data-survey-id=partial]").text()).toContain("Amazon一部未取得");
        expect(wrapper.get("[data-survey-id=complete]").text()).toContain("Amazon取込済み");
        expect(wrapper.get("[data-survey-id=legacy]").text()).toContain("Amazon旧ファイル");
        const load = vi.spyOn(surveys, "load").mockImplementation(async () => {
            const pending = surveys.summaries.find((summary) => summary.id === "pending")!;
            pending.amazonInventoryStatus = "complete";
            pending.amazonBasisAt = "2026-09-18T10:00:00Z";
        });
        await wrapper.get('[data-testid="refresh-history"]').trigger("click");
        await flushPromises();
        expect(load).toHaveBeenCalledOnce();
        expect(wrapper.get("[data-survey-id=pending]").text()).toContain("Amazon取込済み");
        expect(wrapper.get("[data-survey-id=pending]").text()).toContain("Amazon基準: 2026/09/18 10:00");
    });
    it("shows saved Amazon stock normally even when the survey ASIN is missing", () => {
        fixture[0].products = [
            {
                id: "product-1",
                productCode: "A-1001",
                brand: "ブランドA",
                category: "カテゴリA",
                parentAsin: "PARENT-ASIN",
                memo: "",
                skus: [
                    {
                        id: "sku-1",
                        skuCode: "A-1001-01-M",
                        size: "M",
                        asin: "",
                        stock: { amazonOwn: 2, amazonFba: 3, bossOwn: 0, bossRfc: 0, freeStock: 0, ecStock: 0 },
                        memo: "",
                    },
                ],
            },
        ];
        const wrapper = mountSeeded(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });

        const amazonCell = wrapper.get("[data-stock-field=amazon]");
        const freeStockCell = wrapper.get("[data-stock-field=freeStock]");
        const ecStockCell = wrapper.get("[data-stock-field=ecStock]");

        expect(amazonCell.get("[data-stock-value]").text()).toBe("5");
        expect(amazonCell.attributes("data-stock-state")).toBe("low");
        expect(freeStockCell.attributes("data-stock-state")).toBe("zero");
        expect(ecStockCell.attributes("data-stock-state")).toBe("zero");
    });

    it("labels an ASIN-less SP-API SKU as outside scope and includes its other stock in the total", () => {
        fixture[0].amazonSource = "sp_api";
        fixture[0].products = [
            {
                id: "product-1",
                productCode: "A-1001",
                brand: "A",
                category: "A",
                parentAsin: "",
                memo: "",
                skus: [
                    {
                        id: "sku-1",
                        skuCode: "A-1001-M",
                        size: "M",
                        asin: "",
                        memo: "",
                        stock: { amazonOwn: null, amazonFba: null, bossOwn: 4, bossRfc: 5, freeStock: 6, ecStock: 7 },
                    },
                ],
            },
        ];
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });

        expect(wrapper.get("[data-stock-field=amazon] [data-stock-value]").text()).toBe("対象外");
        expect(wrapper.get("[data-stock-field=total]").text()).toBe("22");
    });

    it("shows unimported sources as pending and excludes them from zero-stock filters", async () => {
        fixture[0].amazonSource = "sp_api";
        fixture[0].products = [
            {
                id: "product-1",
                productCode: "A-1001",
                brand: "A",
                category: "A",
                parentAsin: "",
                memo: "",
                skus: [
                    {
                        id: "sku-1",
                        skuCode: "A-1001-M",
                        size: "M",
                        asin: "",
                        memo: "",
                        stock: { amazonOwn: null, amazonFba: null, bossOwn: 4, bossRfc: 0, freeStock: null, ecStock: null },
                    },
                ],
            },
        ];
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });

        expect(wrapper.get("[data-stock-field=freeStock]").text()).toBe("—");
        expect(wrapper.get("[data-stock-field=ecStock]").text()).toBe("—");
        expect(wrapper.get("[data-stock-field=total]").text()).toBe("—");
        expect(wrapper.get("[data-stock-field=boss] [data-stock-value]").text()).toBe("4");
        expect(wrapper.text()).not.toContain("取込待ち・未取得を示します");

        await wrapper.get("[data-stock-condition=freeZero]").trigger("click");
        expect(wrapper.find("[data-stock-field=freeStock]").exists()).toBe(false);
    });

    it("reruns a survey with another setting while at least one source file remains", async () => {
        const surveys = useSurveysStore();
        fixture[0].includesAmazonInventory = false;
        fixture[0].amazonSource = "sp_api";
        fixture[0].files = [
            { id: "3", type: "倉庫毎の在庫数レポート", fileName: "倉庫毎の在庫数レポート.csv", uploadedAt: new Date().toISOString(), sizeKb: 1 },
            { id: "4", type: "KEEP一覧表", fileName: "KEEP一覧表.csv", uploadedAt: new Date().toISOString(), sizeKb: 1 },
            { id: "5", type: "在庫一覧照会表", fileName: "在庫一覧照会表.csv", uploadedAt: new Date().toISOString(), sizeKb: 1 },
        ];
        const settings = useImportSettingsStore();
        settings.settings = [
            { id: "1", name: "売上TOP20", productCodes: ["A-1001"], products: [], lastSyncedAt: null },
            { id: "2", name: "別設定", productCodes: ["B-2001"], products: [], lastSyncedAt: null },
        ];
        const rerunImport = vi
            .spyOn(surveys, "rerunImport")
            .mockResolvedValue({ id: "1", executedAt: "2026-09-16T01:00:00Z", createdAt: "2026-09-18T00:00:00Z", products: [], importSettingName: "別設定", files: [] });
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true, Teleport: true } } });
        const menuButton = wrapper.get('[data-testid="rerun-menu-button"]');
        expect(menuButton.attributes("aria-expanded")).toBe("false");
        expect(wrapper.find('[data-testid="rerun-setting-select"]').exists()).toBe(false);
        await menuButton.trigger("click");
        expect(menuButton.attributes("aria-expanded")).toBe("true");
        expect(wrapper.text()).toContain("差異照合メニュー");
        const select = wrapper.get('[data-testid="rerun-setting-select"] select');
        const button = wrapper.get('[data-testid="rerun-button"]');
        expect(select.attributes("disabled")).toBeUndefined();
        expect(button.attributes("disabled")).toBeDefined();
        await select.setValue("2");
        expect(button.attributes("disabled")).toBeUndefined();
        await button.trigger("click");
        await flushPromises();
        expect(rerunImport).toHaveBeenCalledWith("1", "2");
        expect(push).toHaveBeenCalledWith({ name: "survey-result", params: { id: "1" }, query: { group: "setting:2" } });
        expect(wrapper.find('[data-testid="rerun-setting-select"]').exists()).toBe(false);
        surveys.details["1"]!.files = [surveys.details["1"]!.files[0]!];
        await nextTick();
        expect(menuButton.attributes("disabled")).toBeUndefined();
        surveys.details["1"]!.files = [];
        await nextTick();
        expect(wrapper.text()).toContain("再照合に使える取込ファイルがないため、再照合機能は利用できません。");
        expect(menuButton.attributes("disabled")).toBeDefined();
    });

    it("does not offer a rerun for a legacy five-file survey even when its files remain", async () => {
        const surveys = useSurveysStore();
        fixture[0].includesAmazonInventory = true;
        fixture[0].amazonSource = "survey";
        fixture[0].files = [
            { id: "1", type: "在庫商品レポート", fileName: "在庫商品レポート.txt", uploadedAt: new Date().toISOString(), sizeKb: 1 },
            { id: "3", type: "倉庫毎の在庫数レポート", fileName: "倉庫毎の在庫数レポート.csv", uploadedAt: new Date().toISOString(), sizeKb: 1 },
        ];
        const rerunImport = vi.spyOn(surveys, "rerunImport");
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true, Teleport: true } } });

        const menuButton = wrapper.get('[data-testid="rerun-menu-button"]');
        expect(menuButton.attributes("disabled")).toBeDefined();
        expect(wrapper.get('[data-testid="rerun-unavailable-legacy"]').text()).toBe("Amazon在庫をファイルで取り込んだ旧方式の調査のため、再照合できません。");
        expect(wrapper.text()).not.toContain("再照合に使える取込ファイルがないため");
        await menuButton.trigger("click");
        expect(wrapper.find('[data-testid="rerun-setting-select"]').exists()).toBe(false);
        expect(rerunImport).not.toHaveBeenCalled();
    });

    it("saves a memo only after editing and IME composition finish", async () => {
        vi.useFakeTimers();
        const wrapper = mount(MemoField, { props: { modelValue: "saved" } });
        const input = wrapper.get("input");
        await input.trigger("focusin");
        await input.setValue("draft");
        vi.advanceTimersByTime(2000);
        expect(wrapper.emitted("save")).toBeUndefined();
        await wrapper.setProps({ modelValue: "external" });
        expect((input.element as HTMLInputElement).value).toBe("draft");
        await input.trigger("compositionstart");
        await input.setValue("composed");
        await input.trigger("focusout");
        expect(wrapper.emitted("save")).toBeUndefined();
        await input.trigger("compositionend");
        expect(wrapper.emitted("save")).toEqual([["composed"]]);
        wrapper.unmount();
        vi.useRealTimers();
    });

    it("summarizes all-zero and partial-zero SKUs and compares them with the previous survey", () => {
        const skuOf = (id: string, stock: Record<string, number | null>) => ({
            id,
            skuCode: `A-1001-${id}`,
            colorName: "",
            size: "",
            asin: `ASIN-${id}`,
            tqCode: "",
            tqColorNo: "",
            tqSize: "",
            memo: "",
            stock: { amazonOwn: 1, amazonFba: 1, bossOwn: 1, bossRfc: 1, freeStock: 1, ecStock: 1, ...stock },
        });
        const zero = { amazonOwn: 0, amazonFba: 0, bossOwn: 0, bossRfc: 0, freeStock: 0, ecStock: 0 };
        fixture[0].amazonSource = "sp_api";
        fixture[0].amazonInventoryStatus = "complete";
        fixture[0].products = [
            { id: "p1", productCode: "A-1001", brand: "A", category: "A", parentAsin: "", memo: "", skus: [skuOf("1", zero), skuOf("2", zero), skuOf("3", { ecStock: 0 }), skuOf("4", {})] },
        ];
        fixture.push({
            id: "0",
            executedAt: "2026-09-15T01:00:00Z",
            importSettingName: null,
            amazonSource: "sp_api",
            files: [],
            products: [{ id: "p0", productCode: "A-1001", brand: "A", category: "A", parentAsin: "", memo: "", skus: [skuOf("1", zero), skuOf("3", { ecStock: 0 }), skuOf("4", { freeStock: 0 })] }],
        });
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });

        expect(wrapper.get('[data-summary="all-zero"]').text()).toContain("2");
        expect(wrapper.get('[data-summary="all-zero"]').text()).toContain("前回比 +1 ▲");
        expect(wrapper.get('[data-summary="partial-zero"]').text()).toContain("前回比 −1 ▼");
        expect(wrapper.get('[data-summary="amazon"]').text()).toContain("取込済み");

        const rows = wrapper.findAll("tbody tr");
        expect(rows[0]!.attributes("data-row-state")).toBe("all-zero");
        expect(rows[0]!.text()).not.toContain("全倉庫ゼロ");
        expect(rows[2]!.attributes("data-row-state")).toBeUndefined();
    });

    it("omits the comparison when there is no earlier survey", () => {
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true } } });

        expect(wrapper.get("[data-survey-summary]").text()).not.toContain("前回比");
    });

    it.each(["admin", "editor", "viewer"] as const)("shows only permitted result actions for %s", (role) => {
        useAuthStore().user = { id: "2", loginId: role, role };
        const current = fixture[0];
        current.amazonSource = "sp_api";
        current.files = [{ id: "file-1", type: "倉庫毎の在庫数レポート", fileName: "boss.csv", uploadedAt: new Date().toISOString(), sizeKb: 1 }];
        current.products = [
            {
                id: "product-1",
                productCode: "A-1001",
                brand: "A",
                category: "A",
                parentAsin: "",
                memo: "品番メモ",
                skus: [
                    {
                        id: "sku-1",
                        skuCode: "A-1001-M",
                        size: "M",
                        asin: "",
                        memo: "SKUメモ",
                        stock: { amazonOwn: 1, amazonFba: 0, bossOwn: 2, bossRfc: 0, freeStock: 1, ecStock: 1 },
                    },
                ],
            },
        ];
        const wrapper = mountSeeded(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true, Teleport: true } } });

        expect(wrapper.find('[data-testid="rerun-menu-button"]').exists()).toBe(role !== "viewer");
        expect(wrapper.text()).toContain("絞込結果をExcel出力");
        expect(wrapper.text()).toContain("全件をExcel出力");
        expect(wrapper.find('[aria-label="boss.csvをダウンロード"]').exists()).toBe(false);
        expect(wrapper.get("[data-survey-saved-files]").text()).toContain("取込ファイル: 1/3");
        expect(wrapper.get('[data-testid="survey-files-zip"]').attributes("disabled")).toBeUndefined();
        expect(wrapper.findAllComponents(MemoField)).toHaveLength(role === "admin" ? 2 : 0);
        if (role !== "admin") {
            expect(wrapper.findAll("[data-readonly-memo]").map((memo) => memo.text())).toEqual(["品番メモ", "SKUメモ"]);
        }
    });
});
