import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { setRouteLoading } from "./loading";

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
        },
        {
            path: "/import/settings",
            name: "import-settings",
            component: () => import("@/pages/ImportSettingsPage.vue"),
        },
        {
            path: "/surveys",
            name: "survey-history",
            component: () => import("@/pages/SurveyHistoryPage.vue"),
        },
        {
            path: "/surveys/:id",
            name: "survey-result",
            component: () => import("@/pages/SurveyResultPage.vue"),
            props: true,
        },
        {
            path: "/files",
            name: "file-management",
            component: () => import("@/pages/FileManagementPage.vue"),
        },
        {
            path: "/password",
            name: "password-change",
            component: () => import("@/pages/PasswordChangePage.vue"),
        },
        {
            path: "/:pathMatch(.*)*",
            name: "not-found",
            component: () => import("@/pages/NotFoundPage.vue"),
            meta: { public: true },
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
