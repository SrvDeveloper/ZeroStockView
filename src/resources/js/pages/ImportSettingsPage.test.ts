import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchCrossWalkerProducts } from "@/api/crossWalker";
import { PRODUCT_MASTER } from "@/constants/masterData";
import ImportSettingsPage from "@/pages/ImportSettingsPage.vue";
import { useImportSettingsStore } from "@/stores/importSettings";

vi.mock("@/api/crossWalker", () => {
    return { fetchCrossWalkerProducts: vi.fn() };
});

describe("ImportSettingsPage", () => {
    beforeEach(() => {
        vi.mocked(fetchCrossWalkerProducts).mockReset();
        vi.mocked(fetchCrossWalkerProducts).mockResolvedValue({
            products: PRODUCT_MASTER.slice(0, 20),
            page: 1,
            perPage: 20,
            total: PRODUCT_MASTER.length,
            totalPages: Math.max(1, Math.ceil(PRODUCT_MASTER.length / 20)),
        });
    });

    it("reorders selected products by dragging and dropping", async () => {
        setActivePinia(createPinia());
        const store = useImportSettingsStore();
        store.selectedProductCodes = ["A-1001", "A-1002", "B-2001"];
        store.products = PRODUCT_MASTER.filter((product) => store.selectedProductCodes.includes(product.productCode));
        store.loaded = true;
        vi.spyOn(store, "save").mockImplementation(async (productCodes) => {
            store.selectedProductCodes = productCodes;
        });

        const wrapper = mount(ImportSettingsPage);

        const rows = wrapper.findAll('[draggable="true"]');
        await rows[0].trigger("dragstart");
        await rows[2].trigger("drop");

        const saveButton = wrapper.findAll("button").find((button) => button.text() === "この設定を保存");
        await saveButton?.trigger("click");
        await flushPromises();

        expect(store.selectedProductCodes).toEqual(["A-1002", "B-2001", "A-1001"]);
    });

    it("uses a grabbing cursor until dragging ends", async () => {
        setActivePinia(createPinia());
        const store = useImportSettingsStore();
        store.selectedProductCodes = ["A-1001"];
        store.products = PRODUCT_MASTER.filter((product) => store.selectedProductCodes.includes(product.productCode));
        store.loaded = true;

        const wrapper = mount(ImportSettingsPage);
        const row = wrapper.find('[draggable="true"]');

        expect(row.classes()).toContain("cursor-grab");
        await row.trigger("dragstart");
        expect(row.classes()).toContain("cursor-grabbing");
        await row.trigger("dragend");
        expect(row.classes()).toContain("cursor-grab");
    });
    it("requests 20 products and applies the keyword only after search is submitted", async () => {
        setActivePinia(createPinia());
        const store = useImportSettingsStore();
        store.selectedProductCodes = [];

        const wrapper = mount(ImportSettingsPage);
        await flushPromises();

        expect(fetchCrossWalkerProducts).toHaveBeenLastCalledWith("", 1, 20);
        vi.mocked(fetchCrossWalkerProducts).mockClear();

        await wrapper.find('input[placeholder="品番・SKU・ASIN・TQ情報で検索"]').setValue("B-2001");

        expect(fetchCrossWalkerProducts).not.toHaveBeenCalled();
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(fetchCrossWalkerProducts).toHaveBeenCalledTimes(1);
        expect(fetchCrossWalkerProducts).toHaveBeenLastCalledWith("B-2001", 1, 20);
    });
    it("requests the next API page from pagination", async () => {
        setActivePinia(createPinia());
        const store = useImportSettingsStore();
        store.selectedProductCodes = [];
        vi.mocked(fetchCrossWalkerProducts).mockResolvedValueOnce({
            products: PRODUCT_MASTER,
            page: 1,
            perPage: 20,
            total: 21,
            totalPages: 2,
        });

        const wrapper = mount(ImportSettingsPage);
        await flushPromises();
        const nextButton = wrapper.find('button[aria-label="次のページ"]');

        expect(nextButton.exists()).toBe(true);
        vi.mocked(fetchCrossWalkerProducts).mockResolvedValueOnce({
            products: [PRODUCT_MASTER[0]!],
            page: 2,
            perPage: 20,
            total: 21,
            totalPages: 2,
        });
        await nextButton.trigger("click");
        await flushPromises();

        expect(fetchCrossWalkerProducts).toHaveBeenLastCalledWith("", 2, 20);
    });
});
