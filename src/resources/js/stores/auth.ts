import axios from "axios";
import { defineStore } from "pinia";
import { isUserRole, type UserRole } from "@/constants/roles";

export interface AuthUser {
    id: string;
    loginId: string;
    role: UserRole;
}

export const useAuthStore = defineStore("auth", {
    state: () => ({
        user: null as AuthUser | null,
        initialized: false,
    }),
    getters: {
        isLoggedIn: (state) => state.user !== null,
        role: (state): UserRole | null => state.user?.role ?? null,
    },
    actions: {
        async initialize() {
            if (this.initialized) return;
            try {
                const { data } = await axios.get<{ user: AuthUser | null }>("/api/user");
                this.user = data.user && isUserRole(data.user.role) ? data.user : null;
            } catch {
                this.user = null;
            } finally {
                this.initialized = true;
            }
        },
        async login(loginId: string, password: string): Promise<boolean> {
            try {
                const { data } = await axios.post<{ user: AuthUser }>("/api/login", { login_id: loginId, password });
                if (!data.user || !isUserRole(data.user.role)) {
                    await axios.post("/api/logout");
                    this.user = null;
                    this.initialized = true;
                    return false;
                }
                this.user = data.user;
                this.initialized = true;
                return this.user !== null;
            } catch {
                this.user = null;
                return false;
            }
        },
        async logout() {
            await axios.post("/api/logout");
            this.user = null;
        },
        async changePassword(currentPassword: string, newPassword: string) {
            await axios.put("/api/password", {
                current_password: currentPassword,
                password: newPassword,
                password_confirmation: newPassword,
            });
        },
    },
});
