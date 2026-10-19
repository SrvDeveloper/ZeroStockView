import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchCrossWalkerProducts } from "@/api/crossWalker";
import { PRODUCT_MASTER } from "@/constants/masterData";
import ImportSettingsPage from "@/pages/ImportSettingsPage.vue";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";

const { push, replace } = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }));

vi.mock("vue-router", () => ({
    useRouter: () => ({ push, replace }),
}));

vi.mock("@/api/crossWalker", () => {
    return { fetchCrossWalkerProducts: vi.fn() };
});

function configureStore(productCodes: string[]) {
    const store = useImportSettingsStore();
    store.settings = [
        {
            id: "1",
            name: "売上TOP20",
            productCodes,
            products: PRODUCT_MASTER.filter((product) => productCodes.includes(product.productCode)),
            lastSyncedAt: null,
        },
    ];
    store.selectedSettingId = "1";
    store.loaded = true;

    return store;
}

describe("ImportSettingsPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
        vi.mocked(fetchCrossWalkerProducts).mockReset();
        push.mockReset();
        replace.mockReset();
        vi.mocked(fetchCrossWalkerProducts).mockResolvedValue({
            products: PRODUCT_MASTER.slice(0, 20),
            page: 1,
            perPage: 20,
            total: PRODUCT_MASTER.length,
            totalPages: Math.max(1, Math.ceil(PRODUCT_MASTER.length / 20)),
        });
    });

    it("reorders selected products by dragging and dropping", async () => {
        const store = configureStore(["A-1001", "A-1002", "B-2001"]);
        vi.spyOn(store, "update").mockImplementation(async (settingId, name, productCodes) => {
            const setting = store.settings.find((candidate) => candidate.id === settingId)!;
            setting.name = name;
            setting.productCodes = productCodes;

            return setting;
        });

        const wrapper = mount(ImportSettingsPage, { props: { id: "1" } });

        const rows = wrapper.findAll('[draggable="true"]');
        await rows[0].trigger("dragstart");
        await rows[2].trigger("drop");

        const saveButton = wrapper.findAll("button").find((button) => button.text() === "このグループを保存");
        await saveButton?.trigger("click");
        await flushPromises();

        expect(store.selectedProductCodes).toEqual(["A-1002", "B-2001", "A-1001"]);
    });

    it("uses a grabbing cursor until dragging ends", async () => {
        configureStore(["A-1001"]);

        const wrapper = mount(ImportSettingsPage, { props: { id: "1" } });
        const row = wrapper.find('[draggable="true"]');

        expect(row.classes()).toContain("cursor-grab");
        await row.trigger("dragstart");
        expect(row.classes()).toContain("cursor-grabbing");
        await row.trigger("dragend");
        expect(row.classes()).toContain("cursor-grab");
    });
    it("requests 20 products and applies the keyword only after search is submitted", async () => {
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
        configureStore(["A-1001"]);
        vi.mocked(fetchCrossWalkerProducts).mockResolvedValueOnce({
            products: PRODUCT_MASTER,
            page: 1,
            perPage: 20,
            total: 21,
            totalPages: 2,
        });

        const wrapper = mount(ImportSettingsPage, { props: { id: "1" } });
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
        expect(wrapper.text()).toContain("追加済み");
        const addedRow = wrapper.get('[data-testid="candidate-product-row"]');
        expect(addedRow.attributes("data-added")).toBe("true");
        expect(wrapper.find('button[aria-label="前のページ"]').exists()).toBe(true);
    });

    it("shows the unsaved-change lamp only while the group has changes", async () => {
        configureStore(["A-1001"]);
        const wrapper = mount(ImportSettingsPage, { props: { id: "1" } });

        expect(wrapper.find("[data-dirty-indicator]").exists()).toBe(false);
        expect(wrapper.get("[data-target-capacity]").text()).toContain("1 / 50");

        await wrapper.get('input[placeholder="例: 売上TOP20"]').setValue("秋冬アウター");
        expect(wrapper.get("[data-dirty-indicator]").text()).toContain("未保存の変更があります");

        await wrapper
            .findAll("button")
            .find((button) => button.text() === "変更を取り消す")!
            .trigger("click");
        expect(wrapper.find("[data-dirty-indicator]").exists()).toBe(false);
    });

    it("lets an editor save changes to a group", async () => {
        useAuthStore().user = { id: "2", loginId: "editor", role: "editor" };
        const store = configureStore(["A-1001"]);
        const update = vi.spyOn(store, "update").mockImplementation(async (settingId, name, productCodes) => ({ ...store.settings[0]!, id: settingId, name, productCodes }));
        const wrapper = mount(ImportSettingsPage, { props: { id: "1" } });

        await wrapper.get('input[placeholder="例: 売上TOP20"]').setValue("編集済みグループ");
        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("このグループを保存"))!
            .trigger("click");
        await flushPromises();

        expect(update).toHaveBeenCalledWith("1", "編集済みグループ", ["A-1001"]);
        expect(push).toHaveBeenCalledWith({ name: "import-settings" });
    });

    it("does not search for products if a viewer mounts the editor directly", async () => {
        useAuthStore().user = { id: "2", loginId: "viewer", role: "viewer" };
        const wrapper = mount(ImportSettingsPage);
        await flushPromises();

        expect(wrapper.text()).toBe("");
        expect(fetchCrossWalkerProducts).not.toHaveBeenCalled();
    });
});
