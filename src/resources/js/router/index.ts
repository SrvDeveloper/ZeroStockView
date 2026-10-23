import { createRouter, createWebHistory } from "vue-router";
import { isManualSection } from "@/constants/manual";
import { ADMIN_ROLES, ALL_ROLES, EDITOR_ROLES, canAccess, homeRouteName, type UserRole } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { setRouteLoading } from "./loading";

declare module "vue-router" {
    interface RouteMeta {
        public?: boolean;
        roles?: readonly UserRole[];
        /** 上部バーに出す現在地。調査結果・品番グループの編集・在庫推移の詳細は、画面側で末尾に名前を足す */
        breadcrumb?: string[];
    }
}

const router = createRouter({
    history: createWebHistory(),
    /** 操作マニュアルは節を移ったらページの先頭から、小見出しへのリンク（#id）はその位置から表示する */
    scrollBehavior(to, from, savedPosition) {
        if (savedPosition) return savedPosition;
        if (to.name !== "manual") return false;
        if (to.hash) return { el: to.hash, top: 72 };
        return to.path !== from.path ? { top: 0 } : false;
    },
    routes: [
        { path: "/", redirect: { name: "data-import" } },
        {
            path: "/login",
            name: "login",
            component: () => import("@/pages/LoginPage.vue"),
            meta: { public: true },
        },
        {
            path: "/import",
            name: "data-import",
            component: () => import("@/pages/DataImportPage.vue"),
            meta: { roles: EDITOR_ROLES, breadcrumb: ["データ取込"] },
        },
        {
            path: "/import/settings",
            name: "import-settings",
            component: () => import("@/pages/ImportSettingsListPage.vue"),
            meta: { roles: EDITOR_ROLES, breadcrumb: ["設定", "品番グループ"] },
        },
        {
            path: "/import/settings/new",
            name: "import-setting-new",
            component: () => import("@/pages/ImportSettingsPage.vue"),
            meta: { roles: EDITOR_ROLES, breadcrumb: ["設定", "品番グループ", "新規作成"] },
        },
        {
            path: "/import/settings/:id",
            name: "import-setting-edit",
            component: () => import("@/pages/ImportSettingsPage.vue"),
            meta: { roles: EDITOR_ROLES, breadcrumb: ["設定", "品番グループ"] },
            props: true,
        },
        {
            path: "/surveys",
            name: "survey-history",
            component: () => import("@/pages/SurveyHistoryPage.vue"),
            meta: { roles: ALL_ROLES, breadcrumb: ["在庫調査履歴"] },
        },
        {
            path: "/inventory-trends",
            name: "inventory-trends",
            component: () => import("@/pages/InventoryTrendListPage.vue"),
            meta: { roles: ALL_ROLES, breadcrumb: ["在庫推移"] },
        },
        {
            path: "/inventory-trends/:productCode",
            name: "inventory-trend",
            component: () => import("@/pages/InventoryTrendPage.vue"),
            meta: { roles: ALL_ROLES, breadcrumb: ["在庫推移"] },
            props: true,
        },
        {
            path: "/surveys/:id",
            name: "survey-result",
            component: () => import("@/pages/SurveyResultPage.vue"),
            meta: { roles: ALL_ROLES, breadcrumb: ["在庫調査履歴"] },
            props: (route) => ({ id: route.params.id, initialGroup: typeof route.query.group === "string" ? route.query.group : "" }),
        },
        {
            path: "/files",
            name: "file-management",
            component: () => import("@/pages/FileManagementPage.vue"),
            meta: { roles: ADMIN_ROLES, breadcrumb: ["設定", "ファイル管理"] },
        },
        {
            path: "/manual/:section?",
            name: "manual",
            component: () => import("@/pages/ManualPage.vue"),
            props: true,
            meta: { roles: ALL_ROLES, breadcrumb: ["ヘルプ", "操作マニュアル"] },
        },
        {
            path: "/password",
            name: "password-change",
            component: () => import("@/pages/PasswordChangePage.vue"),
            meta: { roles: ALL_ROLES, breadcrumb: ["アカウント", "パスワード変更"] },
        },
        {
            path: "/api-settings",
            name: "api-connection-settings",
            component: () => import("@/pages/ApiConnectionSettingsPage.vue"),
            meta: { roles: ADMIN_ROLES, breadcrumb: ["設定", "API連携設定"] },
        },
        {
            path: "/users",
            name: "user-management",
            component: () => import("@/pages/UserManagementPage.vue"),
            meta: { roles: ADMIN_ROLES, breadcrumb: ["設定", "ユーザー管理"] },
        },
        {
            path: "/:pathMatch(.*)*",
            name: "not-found",
            component: () => import("@/pages/NotFoundPage.vue"),
            meta: { roles: ALL_ROLES, breadcrumb: ["ページが見つかりません"] },
        },
    ],
});

router.beforeEach(async (to) => {
    setRouteLoading(true);
    const auth = useAuthStore();
    await auth.initialize();
    if (!auth.isLoggedIn && !to.meta.public) {
        return { name: "login", query: { redirect: to.fullPath } };
    }
    if (auth.isLoggedIn && to.name === "login") {
        return { name: homeRouteName(auth.user!.role) };
    }
    if (auth.isLoggedIn && to.meta.roles && !canAccess(auth.role, to.meta.roles)) {
        return { name: homeRouteName(auth.user!.role) };
    }
    if (to.name === "manual") {
        const section = typeof to.params.section === "string" ? to.params.section : "";
        const hashSection = to.hash.slice(1);
        // 1 ページだったころのリンク（/manual#data-import）は、節のページに移す
        if (!section && isManualSection(hashSection)) return { name: "manual", params: { section: hashSection }, replace: true };
        if (section && !isManualSection(section)) return { name: "manual", replace: true };
    }
    return true;
});

router.afterEach(() => {
    setRouteLoading(false);
});

router.onError(() => {
    setRouteLoading(false);
});

export default router;
