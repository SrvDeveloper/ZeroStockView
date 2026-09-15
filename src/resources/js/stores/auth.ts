import axios from "axios";
import { defineStore } from "pinia";

interface AuthUser {
    id: string;
    loginId: string;
}

export const useAuthStore = defineStore("auth", {
    state: () => ({
        user: null as AuthUser | null,
        initialized: false,
    }),
    getters: {
        isLoggedIn: (state) => state.user !== null,
    },
    actions: {
        async initialize() {
            if (this.initialized) return;
            try {
                const { data } = await axios.get<{ user: AuthUser }>("/api/user");
                this.user = data.user;
            } catch {
                this.user = null;
            } finally {
                this.initialized = true;
            }
        },
        async login(loginId: string, password: string): Promise<boolean> {
            try {
                const { data } = await axios.post<{ user: AuthUser }>("/api/login", { login_id: loginId, password });
                this.user = data.user;
                this.initialized = true;
                return true;
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
