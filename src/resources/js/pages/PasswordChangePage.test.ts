import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import PasswordChangePage from "@/pages/PasswordChangePage.vue";
import { useAuthStore } from "@/stores/auth";

describe("PasswordChangePage", () => {
    beforeEach(() => setActivePinia(createPinia()));

    function inputs(wrapper: ReturnType<typeof mount>) {
        const [current, next, confirm] = wrapper.findAll('input[type="password"]');
        return { current: current!, next: next!, confirm: confirm! };
    }

    it("shows which password rules are met", async () => {
        const wrapper = mount(PasswordChangePage);
        const rules = () => wrapper.findAll("[data-password-rules] li").map((rule) => rule.attributes("data-valid"));

        expect(rules()).toEqual(["false", "false"]);
        await inputs(wrapper).next.setValue("abcdefghijk!");
        expect(rules()).toEqual(["true", "true"]);
    });

    it("rejects a confirmation that does not match", async () => {
        const wrapper = mount(PasswordChangePage);
        const changePassword = vi.spyOn(useAuthStore(), "changePassword");
        const { current, next, confirm } = inputs(wrapper);
        await current.setValue("old-password-1");
        await next.setValue("abcdefghijk!");
        await confirm.setValue("abcdefghijk?");

        await wrapper.get("form").trigger("submit");

        expect(wrapper.text()).toContain("新しいパスワード（確認）が一致しません。");
        expect(changePassword).not.toHaveBeenCalled();
    });

    it("changes the password and clears the form", async () => {
        const wrapper = mount(PasswordChangePage);
        const changePassword = vi.spyOn(useAuthStore(), "changePassword").mockResolvedValue();
        const { current, next, confirm } = inputs(wrapper);
        await current.setValue("old-password-1");
        await next.setValue("abcdefghijk!");
        await confirm.setValue("abcdefghijk!");

        await wrapper.get("form").trigger("submit");
        await flushPromises();

        expect(changePassword).toHaveBeenCalledWith("old-password-1", "abcdefghijk!");
        expect((inputs(wrapper).next.element as HTMLInputElement).value).toBe("");
    });
});
