import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import NotFoundPage from "@/pages/NotFoundPage.vue";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({ useRouter: () => ({ push }) }));

describe("NotFoundPage", () => {
    beforeEach(() => push.mockReset());

    it("explains the missing page and leads back to the survey history", async () => {
        const wrapper = mount(NotFoundPage);

        expect(wrapper.get("h1").text()).toBe("ページが見つかりません");
        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("在庫調査履歴へ"))!
            .trigger("click");
        expect(push).toHaveBeenCalledWith({ name: "survey-history" });

        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("データ取込へ"))!
            .trigger("click");
        expect(push).toHaveBeenLastCalledWith({ name: "data-import" });
    });
});
