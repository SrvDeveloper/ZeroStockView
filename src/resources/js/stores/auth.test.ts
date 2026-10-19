import axios from "axios";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useAuthStore } from "@/stores/auth";
import type { UserRole } from "@/constants/roles";

vi.mock("axios", () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

describe("auth store", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it.each(["admin", "editor", "viewer"] as UserRole[])("keeps the %s role after login and reload", async (role) => {
        const user = { id: "1", loginId: role, role };
        vi.mocked(axios.post).mockResolvedValue({ data: { user } });
        vi.mocked(axios.get).mockResolvedValue({ data: { user } });

        const auth = useAuthStore();
        expect(await auth.login(role, "password1234")).toBe(true);
        expect(auth.role).toBe(role);

        auth.$patch({ user: null, initialized: false });
        await auth.initialize();
        expect(auth.user).toEqual(user);
        expect(auth.role).toBe(role);
        expect(axios.get).toHaveBeenCalledWith("/api/user");
    });

    it("clears the user and role on logout", async () => {
        const auth = useAuthStore();
        auth.user = { id: "1", loginId: "viewer", role: "viewer" };
        vi.mocked(axios.post).mockResolvedValue({});

        await auth.logout();

        expect(auth.user).toBeNull();
        expect(auth.role).toBeNull();
        expect(axios.post).toHaveBeenCalledWith("/api/logout");
    });

    it("rejects an unknown role in the current-user response", async () => {
        vi.mocked(axios.get).mockResolvedValue({ data: { user: { id: "1", loginId: "unknown", role: "unknown" } } });

        const auth = useAuthStore();
        await auth.initialize();

        expect(auth.isLoggedIn).toBe(false);
        expect(auth.initialized).toBe(true);
    });

    it("ends a login session if the response has an unknown role", async () => {
        vi.mocked(axios.post)
            .mockResolvedValueOnce({ data: { user: { id: "1", loginId: "unknown", role: "unknown" } } })
            .mockResolvedValueOnce({});

        const auth = useAuthStore();
        expect(await auth.login("unknown", "password1234")).toBe(false);
        expect(auth.user).toBeNull();
        expect(axios.post).toHaveBeenLastCalledWith("/api/logout");
    });
});
