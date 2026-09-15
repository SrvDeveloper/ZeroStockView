import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchProductPage, PRODUCT_MASTER } from "@/constants/masterData";
import ImportSettingsPage from "@/pages/ImportSettingsPage.vue";
import { useImportSettingsStore } from "@/stores/importSettings";

vi.mock("@/constants/masterData", async (importOriginal) => {
    const actual = await importOriginal<typeof import("@/constants/masterData")>();
    return { ...actual, fetchProductPage: vi.fn(actual.fetchProductPage) };
});

describe("ImportSettingsPage", () => {
    beforeEach(() => {
        vi.mocked(fetchProductPage).mockClear();
    });

    it("reorders selected products by dragging and dropping", async () => {
        setActivePinia(createPinia());
        const store = useImportSettingsStore();
        store.selectedProductCodes = ["A-1001", "A-1002", "B-2001"];

        const wrapper = mount(ImportSettingsPage);

        const rows = wrapper.findAll('[draggable="true"]');
        await rows[0].trigger("dragstart");
        await rows[2].trigger("drop");

        const saveButton = wrapper.findAll("button").find((button) => button.text() === "この設定を保存");
        await saveButton?.trigger("click");

        expect(store.selectedProductCodes).toEqual(["A-1002", "B-2001", "A-1001"]);
    });

    it("uses a grabbing cursor until dragging ends", async () => {
        setActivePinia(createPinia());
        const store = useImportSettingsStore();
        store.selectedProductCodes = ["A-1001"];

        const wrapper = mount(ImportSettingsPage);
        const row = wrapper.find('[draggable="true"]');

        expect(row.classes()).toContain("cursor-grab");
        await row.trigger("dragstart");
        expect(row.classes()).toContain("cursor-grabbing");
        await row.trigger("dragend");
        expect(row.classes()).toContain("cursor-grab");
    });
    it("requests 20 products and applies filters only after search is submitted", async () => {
        setActivePinia(createPinia());
        const store = useImportSettingsStore();
        store.selectedProductCodes = [];

        const wrapper = mount(ImportSettingsPage);
        await flushPromises();

        expect(fetchProductPage).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, perPage: 20 }));
        vi.mocked(fetchProductPage).mockClear();

        await wrapper.find('input[placeholder="品番で検索"]').setValue("B-2001");
        const selects = wrapper.findAll("select");
        await selects[0].setValue("BRAVO");
        await selects[1].setValue("トップス");

        expect(fetchProductPage).not.toHaveBeenCalled();
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(fetchProductPage).toHaveBeenCalledTimes(1);
        expect(fetchProductPage).toHaveBeenLastCalledWith(
            expect.objectContaining({
                query: "B-2001",
                brand: "BRAVO",
                category: "トップス",
                page: 1,
                perPage: 20,
            }),
        );
    });
    it("requests the next API page from pagination", async () => {
        setActivePinia(createPinia());
        const store = useImportSettingsStore();
        store.selectedProductCodes = [];
        vi.mocked(fetchProductPage).mockResolvedValueOnce({
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
        vi.mocked(fetchProductPage).mockResolvedValueOnce({
            products: [PRODUCT_MASTER[0]!],
            page: 2,
            perPage: 20,
            total: 21,
            totalPages: 2,
        });
        await nextButton.trigger("click");
        await flushPromises();

        expect(fetchProductPage).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, perPage: 20 }));
    });
});
