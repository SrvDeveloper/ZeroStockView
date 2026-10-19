import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import router from "@/router";
import type { UserRole } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";

describe("router", () => {
    beforeEach(async () => {
        setActivePinia(createPinia());
        useAuthStore().$patch({
            user: { id: "1", loginId: "admin", role: "admin" },
            initialized: true,
        });
        await router.replace("/manual");
    });

    it("redirects the root path to data import", async () => {
        await router.push("/");

        expect(router.currentRoute.value.name).toBe("data-import");
        expect(router.currentRoute.value.path).toBe("/import");
    });

    it("redirects logged-in users from login to data import", async () => {
        await router.push("/login");

        expect(router.currentRoute.value.name).toBe("data-import");
        expect(router.currentRoute.value.path).toBe("/import");
    });

    it("provides the operation manual route", async () => {
        await router.push("/manual");

        expect(router.currentRoute.value.name).toBe("manual");
        expect(router.currentRoute.value.path).toBe("/manual");
    });

    it("provides the API connection settings route", async () => {
        await router.push("/api-settings");

        expect(router.currentRoute.value.name).toBe("api-connection-settings");
        expect(router.currentRoute.value.path).toBe("/api-settings");
    });

    it("provides the inventory trends route", async () => {
        await router.push("/inventory-trends");

        expect(router.currentRoute.value.name).toBe("inventory-trends");
        expect(router.currentRoute.value.path).toBe("/inventory-trends");
    });

    it.each(["admin", "editor", "viewer"] as UserRole[])("sends %s to the correct home screen", async (role) => {
        useAuthStore().user = { id: "1", loginId: role, role };

        await router.push("/");
        expect(router.currentRoute.value.name).toBe(role === "viewer" ? "survey-history" : "data-import");

        await router.push("/login");
        expect(router.currentRoute.value.name).toBe(role === "viewer" ? "survey-history" : "data-import");
    });

    it("protects editor and admin routes from viewers, including direct URLs", async () => {
        useAuthStore().user = { id: "1", loginId: "viewer", role: "viewer" };

        for (const path of ["/import", "/import/settings", "/import/settings/new", "/import/settings/1", "/files", "/api-settings", "/users"]) {
            await router.push(path);
            expect(router.currentRoute.value.name).toBe("survey-history");
        }

        for (const path of ["/surveys", "/surveys/1", "/inventory-trends", "/manual", "/password"]) {
            await router.push(path);
            expect(router.currentRoute.value.path).toBe(path);
        }
    });

    it("allows editor screens but redirects an editor away from admin screens", async () => {
        useAuthStore().user = { id: "1", loginId: "editor", role: "editor" };

        for (const path of ["/import", "/import/settings", "/import/settings/new", "/import/settings/1"]) {
            await router.push(path);
            expect(router.currentRoute.value.path).toBe(path);
        }

        for (const path of ["/files", "/api-settings", "/users"]) {
            await router.push(path);
            expect(router.currentRoute.value.name).toBe("data-import");
        }
    });

    it("sends guests to login with the requested path", async () => {
        useAuthStore().$patch({ user: null, initialized: true });

        await router.push("/users");
        expect(router.currentRoute.value.name).toBe("login");
        expect(router.currentRoute.value.query.redirect).toBe("/users");

        await router.push("/missing");
        expect(router.currentRoute.value.name).toBe("login");
    });
});
