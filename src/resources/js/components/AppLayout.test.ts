import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { reactive } from "vue";
import AppSidebar from "@/components/AppSidebar.vue";
import AppTopBar from "@/components/AppTopBar.vue";
import { isNavItemActive, NAV_ITEMS } from "@/constants/navigation";
import type { UserRole } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { toSurveySummary } from "@/utils/stock";

const route = reactive<{ name: string; params: Record<string, string>; meta: { breadcrumb?: string[] } }>({ name: "data-import", params: {}, meta: {} });

vi.mock("vue-router", () => ({
    useRoute: () => route,
    useRouter: () => ({ resolve: () => ({ href: "/login" }) }),
}));

const RouterLinkStub = { props: ["to"], template: '<a v-bind="$attrs"><slot /></a>' };

function setRoute(name: string, breadcrumb: string[], params: Record<string, string> = {}) {
    route.name = name;
    route.params = params;
    route.meta = { breadcrumb };
}

describe("navigation", () => {
    it("treats child screens as part of their menu item", () => {
        const history = NAV_ITEMS.find((item) => item.name === "survey-history")!;
        const groups = NAV_ITEMS.find((item) => item.name === "import-settings")!;

        expect(isNavItemActive(history, "survey-result")).toBe(true);
        expect(isNavItemActive(groups, "import-setting-edit")).toBe(true);
        expect(
            isNavItemActive(
                NAV_ITEMS.find((item) => item.name === "inventory-trends")!,
                "inventory-trend",
            ),
        ).toBe(true);
        expect(isNavItemActive(groups, "survey-result")).toBe(false);
        expect(isNavItemActive(history, undefined)).toBe(false);
    });
});

describe("AppSidebar", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
        setRoute("survey-result", ["在庫調査履歴"], { id: "1" });
    });

    it("groups menu items and marks the current one", () => {
        const wrapper = mount(AppSidebar, { props: { open: false }, global: { stubs: { RouterLink: RouterLinkStub, IntegrationStatus: true } } });

        expect(wrapper.text()).toContain("調査");
        expect(wrapper.text()).toContain("設定");
        expect(wrapper.text()).toContain("ヘルプ");
        expect(wrapper.get('[data-nav-item="survey-history"]').attributes("aria-current")).toBe("page");
        expect(wrapper.get('[data-nav-item="data-import"]').attributes("aria-current")).toBeUndefined();
    });

    it("closes the menu when an item is chosen", async () => {
        const wrapper = mount(AppSidebar, { props: { open: true }, global: { stubs: { RouterLink: RouterLinkStub, IntegrationStatus: true } } });

        await wrapper.get('[data-nav-item="manual"]').trigger("click");

        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it.each([
        ["viewer", ["survey-history", "inventory-trends", "manual"], false],
        ["editor", ["data-import", "survey-history", "inventory-trends", "import-settings", "manual"], true],
        ["admin", ["data-import", "survey-history", "inventory-trends", "import-settings", "file-management", "api-connection-settings", "user-management", "manual"], true],
    ] as [UserRole, string[], boolean][])("shows the correct navigation for %s", (role, expectedItems, hasStatus) => {
        useAuthStore().user = { id: "1", loginId: role, role };
        const wrapper = mount(AppSidebar, { props: { open: false }, global: { stubs: { RouterLink: RouterLinkStub, IntegrationStatus: true } } });

        expect(wrapper.findAll("[data-nav-item]").map((item) => item.attributes("data-nav-item"))).toEqual(expectedItems);
        expect(wrapper.find("integration-status-stub").exists()).toBe(hasStatus);
        expect(wrapper.findAllComponents(RouterLinkStub)[0].props("to")).toEqual({ name: role === "viewer" ? "survey-history" : "data-import" });
    });
});

describe("AppTopBar", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
    });

    it("shows the static breadcrumb of the route", () => {
        setRoute("file-management", ["設定", "ファイル管理"]);
        const wrapper = mount(AppTopBar, { props: { menuOpen: false }, global: { stubs: { RouterLink: RouterLinkStub } } });

        expect(wrapper.get("[data-breadcrumb]").text()).toBe("設定/ファイル管理");
        expect(wrapper.text()).toContain("admin");
    });

    it("adds the survey date on the survey result screen", () => {
        useSurveysStore().summaries = [toSurveySummary({ id: "7", executedAt: "2026-10-16T00:00:00+09:00", products: [], importSettingName: null, files: [] })];
        setRoute("survey-result", ["在庫調査履歴"], { id: "7" });
        const wrapper = mount(AppTopBar, { props: { menuOpen: false }, global: { stubs: { RouterLink: RouterLinkStub } } });

        expect(wrapper.get("[data-breadcrumb]").text()).toBe("在庫調査履歴/2026-10-16 の調査結果");
    });

    it("adds the group name on the group edit screen", () => {
        useImportSettingsStore().settings = [{ id: "3", name: "秋冬アウター", productCodes: [], products: [], lastSyncedAt: null }];
        setRoute("import-setting-edit", ["設定", "品番グループ"], { id: "3" });
        const wrapper = mount(AppTopBar, { props: { menuOpen: false }, global: { stubs: { RouterLink: RouterLinkStub } } });

        expect(wrapper.get("[data-breadcrumb]").text()).toBe("設定/品番グループ/秋冬アウター");
    });

    it("adds the product code on the inventory trend detail screen", () => {
        setRoute("inventory-trend", ["在庫推移"], { productCode: "ZS-2408-01" });
        const wrapper = mount(AppTopBar, { props: { menuOpen: false }, global: { stubs: { RouterLink: RouterLinkStub } } });

        expect(wrapper.get("[data-breadcrumb]").text()).toBe("在庫推移/ZS-2408-01");
    });

    it.each(["admin", "editor", "viewer"] as UserRole[])("keeps the account menu for %s", async (role) => {
        useAuthStore().user = { id: "1", loginId: role, role };
        setRoute("manual", ["ヘルプ", "操作マニュアル"]);
        const wrapper = mount(AppTopBar, { props: { menuOpen: false }, global: { stubs: { RouterLink: RouterLinkStub } } });

        await wrapper.get('[aria-label="アカウントメニュー"]').trigger("click");

        expect(wrapper.text()).toContain("パスワード変更");
        expect(wrapper.text()).toContain("ログアウト");
    });
});
