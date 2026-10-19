import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import LoginPage from "@/pages/LoginPage.vue";
import type { UserRole } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock("vue-router", () => ({ useRouter: () => ({ push }), useRoute: () => ({ query: {} }) }));

describe("LoginPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        push.mockReset();
    });

    it.each(["admin", "editor", "viewer"] as UserRole[])("opens the correct screen after %s login", async (role) => {
        const auth = useAuthStore();
        vi.spyOn(auth, "login").mockImplementation(async () => {
            auth.user = { id: "1", loginId: role, role };
            return true;
        });
        const loadSurveys = vi.spyOn(useSurveysStore(), "load").mockResolvedValue();
        const loadSettings = vi.spyOn(useImportSettingsStore(), "load").mockResolvedValue();
        const wrapper = mount(LoginPage);

        const inputs = wrapper.findAll("input");
        await inputs[0].setValue(role);
        await inputs[1].setValue("password1234");
        await wrapper.get("form").trigger("submit");
        await flushPromises();

        expect(push).toHaveBeenCalledWith({ name: role === "viewer" ? "survey-history" : "data-import" });
        expect(loadSurveys).toHaveBeenCalledOnce();
        expect(loadSettings).toHaveBeenCalledTimes(role === "viewer" ? 0 : 1);
    });
});
