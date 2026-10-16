import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { setRouteLoading } from "./loading";

declare module "vue-router" {
    interface RouteMeta {
        public?: boolean;
        /** 上部バーに出す現在地。調査結果と品番グループの編集は、画面側で末尾に名前を足す */
        breadcrumb?: string[];
    }
}

const router = createRouter({
    history: createWebHistory(),
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
            meta: { breadcrumb: ["データ取込"] },
        },
        {
            path: "/import/settings",
            name: "import-settings",
            component: () => import("@/pages/ImportSettingsListPage.vue"),
            meta: { breadcrumb: ["設定", "品番グループ"] },
        },
        {
            path: "/import/settings/new",
            name: "import-setting-new",
            component: () => import("@/pages/ImportSettingsPage.vue"),
            meta: { breadcrumb: ["設定", "品番グループ", "新規作成"] },
        },
        {
            path: "/import/settings/:id",
            name: "import-setting-edit",
            component: () => import("@/pages/ImportSettingsPage.vue"),
            meta: { breadcrumb: ["設定", "品番グループ"] },
            props: true,
        },
        {
            path: "/surveys",
            name: "survey-history",
            component: () => import("@/pages/SurveyHistoryPage.vue"),
            meta: { breadcrumb: ["在庫調査履歴"] },
        },
        {
            path: "/inventory-trends",
            name: "inventory-trends",
            component: () => import("@/pages/InventoryTrendPage.vue"),
            meta: { breadcrumb: ["在庫推移"] },
        },
        {
            path: "/surveys/:id",
            name: "survey-result",
            component: () => import("@/pages/SurveyResultPage.vue"),
            meta: { breadcrumb: ["在庫調査履歴"] },
            props: (route) => ({ id: route.params.id, initialGroup: typeof route.query.group === "string" ? route.query.group : "" }),
        },
        {
            path: "/files",
            name: "file-management",
            component: () => import("@/pages/FileManagementPage.vue"),
            meta: { breadcrumb: ["設定", "ファイル管理"] },
        },
        {
            path: "/manual",
            name: "manual",
            component: () => import("@/pages/ManualPage.vue"),
            meta: { breadcrumb: ["ヘルプ", "操作マニュアル"] },
        },
        {
            path: "/password",
            name: "password-change",
            component: () => import("@/pages/PasswordChangePage.vue"),
            meta: { breadcrumb: ["アカウント", "パスワード変更"] },
        },
        {
            path: "/api-settings",
            name: "api-connection-settings",
            component: () => import("@/pages/ApiConnectionSettingsPage.vue"),
            meta: { breadcrumb: ["設定", "API連携設定"] },
        },
        {
            path: "/:pathMatch(.*)*",
            name: "not-found",
            component: () => import("@/pages/NotFoundPage.vue"),
            meta: { public: true, breadcrumb: ["ページが見つかりません"] },
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
        return { name: "data-import" };
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
