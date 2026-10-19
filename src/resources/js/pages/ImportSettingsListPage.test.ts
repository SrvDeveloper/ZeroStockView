import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import ImportSettingsListPage from "@/pages/ImportSettingsListPage.vue";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({
    useRouter: () => ({ push }),
}));

function configureStore() {
    const store = useImportSettingsStore();
    store.settings = [
        {
            id: "1",
            name: "売上TOP20",
            productCodes: ["A-1001", "A-1002"],
            products: [],
            lastSyncedAt: null,
        },
        {
            id: "2",
            name: "サングラス",
            productCodes: ["B-2001"],
            products: [],
            lastSyncedAt: null,
        },
    ];
    store.selectedSettingId = "1";
    store.loaded = true;

    return store;
}

describe("ImportSettingsListPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
        push.mockReset();
    });

    it("lists settings and opens the selected setting for editing", async () => {
        configureStore();
        const wrapper = mount(ImportSettingsListPage);

        expect(wrapper.text()).toContain("全2件");
        expect(wrapper.text()).toContain("売上TOP20");
        expect(wrapper.text()).toContain("サングラス");
        expect(wrapper.text()).toContain("A-1001");

        await wrapper
            .findAll("button")
            .find((button) => button.text() === "売上TOP20")
            ?.trigger("click");

        expect(push).toHaveBeenCalledWith({ name: "import-setting-edit", params: { id: "1" } });
    });

    it("deletes a setting from the list", async () => {
        const store = configureStore();
        vi.spyOn(store, "remove").mockImplementation(async (settingId) => {
            store.settings = store.settings.filter((setting) => setting.id !== settingId);
        });
        const wrapper = mount(ImportSettingsListPage, {
            global: { stubs: { teleport: true } },
        });

        await wrapper.findAll('button[aria-label="削除"]')[0]!.trigger("click");
        await wrapper
            .findAll("button")
            .find((button) => button.text() === "削除する")
            ?.trigger("click");
        await flushPromises();

        expect(store.remove).toHaveBeenCalledWith("1");
        expect(wrapper.text()).not.toContain("売上TOP20");
    });

    it("lets editors create and edit groups without showing deletion", async () => {
        useAuthStore().user = { id: "2", loginId: "editor", role: "editor" };
        const store = configureStore();
        const remove = vi.spyOn(store, "remove");
        const wrapper = mount(ImportSettingsListPage, { global: { stubs: { teleport: true } } });

        expect(wrapper.find('button[aria-label="削除"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain("品番グループの削除");
        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("新しいグループを作成"))
            ?.trigger("click");
        expect(push).toHaveBeenCalledWith({ name: "import-setting-new" });

        await wrapper.get('[data-setting-id="1"]').findAll("button")[1]!.trigger("click");
        expect(push).toHaveBeenCalledWith({ name: "import-setting-edit", params: { id: "1" } });
        expect(remove).not.toHaveBeenCalled();
    });

    it("summarizes unique products and SKUs across groups and shows the last sync time", () => {
        const store = configureStore();
        const product = (productCode: string, skuCodes: string[]) => ({
            productCode,
            brand: "",
            category: "",
            parentAsin: "",
            skus: skuCodes.map((skuCode) => ({ skuCode, colorName: "", size: "", asin: "", tqCode: "", tqColorNo: "", tqSize: "" })),
        });
        store.settings[0]!.products = [product("A-1001", ["A-1001-M", "A-1001-L"]), product("A-1002", ["A-1002-F"])];
        store.settings[0]!.lastSyncedAt = "2026-10-14T08:32:00Z";
        store.settings[1]!.productCodes = ["B-2001", "A-1001"];
        store.settings[1]!.products = [product("B-2001", ["B-2001-F"]), product("A-1001", ["A-1001-M", "A-1001-L"])];
        const wrapper = mount(ImportSettingsListPage);

        const summary = wrapper.get("[data-target-summary]").text();
        expect(summary).toContain("グループ 2");
        expect(summary).toContain("取込対象の品番（重複を除く） 3");
        expect(summary).toContain("取込対象の SKU 4");
        expect(wrapper.get('[data-setting-id="1"] [data-last-synced]').text()).not.toBe("—");
        expect(wrapper.get('[data-setting-id="2"] [data-last-synced]').text()).toBe("—");
    });
});
