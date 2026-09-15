import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import router from "@/router";
import { useAuthStore } from "@/stores/auth";

describe("router", () => {
    beforeEach(async () => {
        setActivePinia(createPinia());
        useAuthStore().$patch({
            user: { id: "1", loginId: "admin" },
            initialized: true,
        });
        await router.replace("/import");
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
});
