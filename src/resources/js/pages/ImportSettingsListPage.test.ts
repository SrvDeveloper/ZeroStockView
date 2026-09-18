import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import ImportSettingsListPage from "@/pages/ImportSettingsListPage.vue";
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
});
