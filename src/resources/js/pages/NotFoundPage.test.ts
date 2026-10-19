import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import NotFoundPage from "@/pages/NotFoundPage.vue";
import { useAuthStore } from "@/stores/auth";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({ useRouter: () => ({ push }) }));

describe("NotFoundPage", () => {
    beforeEach(() => {
        push.mockReset();
        setActivePinia(createPinia());
    });

    it("explains the missing page and leads back to the survey history", async () => {
        useAuthStore().user = { id: "1", loginId: "viewer", role: "viewer" };
        const wrapper = mount(NotFoundPage);

        expect(wrapper.get("h1").text()).toBe("ページが見つかりません");
        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("在庫調査履歴へ"))!
            .trigger("click");
        expect(push).toHaveBeenCalledWith({ name: "survey-history" });

        expect(wrapper.text()).not.toContain("データ取込へ");
    });

    it("leads editors and admins back to data import", async () => {
        useAuthStore().user = { id: "1", loginId: "editor", role: "editor" };
        const wrapper = mount(NotFoundPage);

        await wrapper.get("button").trigger("click");
        expect(push).toHaveBeenCalledWith({ name: "data-import" });
    });
});
