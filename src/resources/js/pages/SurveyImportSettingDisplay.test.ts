import { flushPromises, mount } from "@vue/test-utils";
import { nextTick } from "vue";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SurveyHistoryPage from "@/pages/SurveyHistoryPage.vue";
import MemoField from "@/components/ui/MemoField.vue";
import SurveyResultPage from "@/pages/SurveyResultPage.vue";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({
    useRouter: () => ({ push }),
}));

function seedSurvey() {
    const surveys = useSurveysStore();
    surveys.surveys = [
        {
            id: "1",
            executedAt: "2026-09-16T01:00:00Z",
            importSettingName: "売上TOP20",
            products: [],
            files: [],
        },
    ];

    return surveys;
}

describe("survey import setting display", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        push.mockReset();
        seedSurvey();
    });

    it("shows the import setting in the survey history", () => {
        const wrapper = mount(SurveyHistoryPage);

        expect(wrapper.text()).toContain("取込設定");
        expect(wrapper.text()).toContain("売上TOP20");
    });

    it("shows the import setting in the survey result", () => {
        const wrapper = mount(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });

        expect(wrapper.text()).toContain("取込設定: 売上TOP20");
    });

    it("clears each text filter from its clear button", async () => {
        const wrapper = mount(SurveyResultPage, {
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
        const surveys = useSurveysStore();
        surveys.surveys[0].products = [
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
                        colorName: "黒",
                        size: "M",
                        asin: "ASIN-1",
                        tqCode: "TQ-1",
                        tqColorNo: "01",
                        tqSize: "M",
                        stock: { amazonOwn: 0, amazonFba: 3, bossOwn: 0, bossRfc: 0, freeStock: 5, ecStock: 6 },
                        memo: "",
                    },
                ],
            },
        ];
        const wrapper = mount(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });

        const amazonCell = wrapper.get("[data-stock-field=amazon]");
        const bossCell = wrapper.get("[data-stock-field=boss]");
        const totalStockCell = wrapper.get("[data-stock-field=total]");
        const amazonBreakdown = amazonCell.get(".stock-breakdown-tooltip");
        const bossBreakdown = bossCell.get(".stock-breakdown-tooltip");
        expect(wrapper.find("[role=switch][aria-label=内訳表示]").exists()).toBe(false);
        expect(wrapper.classes()).not.toContain("stock-breakdown-enabled");
        expect(wrapper.text()).toContain("Ctrlキーを押しながらAmazon・BOSSの数値にマウスを置くと内訳を表示します");

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
        expect(amazonCell.get(".stock-total-value").text()).toBe("3");
        expect(amazonCell.classes()).toContain("bg-amber-50");
        expect(amazonCell.attributes("tabindex")).toBe("0");
        expect(amazonBreakdown.text()).toContain("Amazon内訳");
        expect(amazonBreakdown.findAll("strong").map((value) => value.text())).toEqual(["0", "3"]);
        expect(bossCell.get(".stock-total-value").text()).toBe("0");
        expect(bossCell.classes()).toContain("bg-rose-50");
        expect(bossCell.classes()).not.toContain("group-hover:bg-rose-100");
        expect(bossBreakdown.text()).toContain("BOSS内訳");
        expect(bossBreakdown.findAll("strong").map((value) => value.text())).toEqual(["0", "0"]);
        expect(wrapper.get("thead").text()).toContain("在庫総数");
        expect(totalStockCell.text()).toBe("14");
        expect(totalStockCell.classes()).toContain("font-semibold");
        expect(wrapper.findAll("[role=tooltip]")).toHaveLength(2);
        expect(wrapper.get("[data-stock-field=freeStock]").classes()).not.toContain("bg-amber-50");
        expect(wrapper.get("[data-stock-field=ecStock]").classes()).not.toContain("bg-amber-50");
        await wrapper.get('input[placeholder="SKUで絞込"]').setValue("A-1001");
        expect(wrapper.get("tbody tr").classes()).toContain("bg-sky-50");
        expect(wrapper.get("tbody tr").classes()).not.toContain("hover:bg-slate-50");
        expect(wrapper.get("[data-sku-cell]").classes()).toContain("group-hover:bg-slate-100");
        expect(wrapper.get("tbody tr").classes()).not.toContain("hover:bg-indigo-100");
        expect(wrapper.get("[data-stock-field=amazon]").classes()).not.toContain("group-hover:bg-amber-100");
        expect(wrapper.get("tbody tr").classes()).not.toContain("bg-amber-50");
        expect(wrapper.get("[data-stock-field=amazon]").classes()).toContain("bg-amber-50");
        await wrapper.get('button[aria-label="SKUの絞込をクリア"]').trigger("click");
        const andOperator = wrapper.get('[data-condition-operator="and"]');
        const orOperator = wrapper.get('[data-condition-operator="or"]');
        expect(andOperator.attributes("aria-pressed")).toBe("true");
        await wrapper.get('[data-stock-condition="bossZero"]').trigger("click");
        await wrapper.get('[data-stock-condition="ecZero"]').trigger("click");
        expect(wrapper.find("tbody tr").exists()).toBe(false);
        await orOperator.trigger("click");
        expect(orOperator.attributes("aria-pressed")).toBe("true");
        expect(andOperator.attributes("aria-pressed")).toBe("false");
        expect(wrapper.find("tbody tr").exists()).toBe(true);
        await wrapper.get("[data-clear-filters]").trigger("click");
        expect(andOperator.attributes("aria-pressed")).toBe("true");
        await wrapper.get('[data-stock-condition="amazonHas"]').trigger("click");
        await wrapper.get('[data-stock-condition="bossHas"]').trigger("click");
        expect(wrapper.find("tbody tr").exists()).toBe(false);
        await orOperator.trigger("click");
        expect(wrapper.find("tbody tr").exists()).toBe(true);
    });
    it("dims Amazon stock when the SKU has no child ASIN", () => {
        const surveys = useSurveysStore();
        surveys.surveys[0].products = [
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
                        colorName: "黒",
                        size: "M",
                        asin: "",
                        tqCode: "TQ-1",
                        tqColorNo: "01",
                        tqSize: "M",
                        stock: { amazonOwn: 2, amazonFba: 3, bossOwn: 0, bossRfc: 0, freeStock: 0, ecStock: 0 },
                        memo: "",
                    },
                ],
            },
        ];
        const wrapper = mount(SurveyResultPage, {
            props: { id: "1" },
            global: { stubs: { RouterLink: true } },
        });

        const amazonCell = wrapper.get("[data-stock-field=amazon]");
        const freeStockCell = wrapper.get("[data-stock-field=freeStock]");
        const ecStockCell = wrapper.get("[data-stock-field=ecStock]");

        expect(amazonCell.get(".stock-total-value").text()).toBe("5");
        expect(amazonCell.classes()).toContain("text-slate-300");
        expect(amazonCell.classes()).not.toContain("bg-amber-50");
        expect(amazonCell.classes()).not.toContain("font-semibold");
        expect(freeStockCell.classes()).toContain("text-slate-300");
        expect(ecStockCell.classes()).toContain("text-slate-300");
    });

    it("reruns a survey with another setting only while all source files remain", async () => {
        const surveys = useSurveysStore();
        surveys.surveys[0].files = [
            { id: "1", type: "在庫商品レポート", fileName: "在庫商品レポート.txt", uploadedAt: "2026-09-16T01:00:00Z", sizeKb: 1 },
            { id: "2", type: "FBA在庫管理レポート", fileName: "FBA在庫管理レポート.csv", uploadedAt: "2026-09-16T01:00:00Z", sizeKb: 1 },
            { id: "3", type: "倉庫毎の在庫数レポート", fileName: "倉庫毎の在庫数レポート.csv", uploadedAt: "2026-09-16T01:00:00Z", sizeKb: 1 },
            { id: "4", type: "KEEP一覧表", fileName: "KEEP一覧表.csv", uploadedAt: "2026-09-16T01:00:00Z", sizeKb: 1 },
            { id: "5", type: "在庫一覧照会表", fileName: "在庫一覧照会表.csv", uploadedAt: "2026-09-16T01:00:00Z", sizeKb: 1 },
        ];
        const settings = useImportSettingsStore();
        settings.settings = [
            { id: "1", name: "売上TOP20", productCodes: ["A-1001"], products: [], lastSyncedAt: null },
            { id: "2", name: "別設定", productCodes: ["B-2001"], products: [], lastSyncedAt: null },
        ];
        const rerunImport = vi.spyOn(surveys, "rerunImport").mockResolvedValue({ id: "2", executedAt: "2026-09-18T00:00:00Z", products: [], importSettingName: "別設定", files: [] });
        const wrapper = mount(SurveyResultPage, { props: { id: "1" }, global: { stubs: { RouterLink: true, Teleport: true } } });
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
        expect(push).toHaveBeenCalledWith({ name: "survey-result", params: { id: "2" } });
        expect(wrapper.find('[data-testid="rerun-setting-select"]').exists()).toBe(false);
        surveys.surveys[0].files = [];
        await nextTick();
        expect(wrapper.text()).toContain("取込ファイルが削除されているため、再照合機能は利用できません。");
        expect(menuButton.attributes("disabled")).toBeDefined();
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
});
