import axios from "axios";
import { defineStore } from "pinia";
import { isUserRole, type UserRole } from "@/constants/roles";

export interface AuthUser {
    id: string;
    loginId: string;
    role: UserRole;
}

const LOGIN_FAILED_MESSAGE = "認証情報が正しくありません。";

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
        /** ログインできたら true、できなければ画面に出すメッセージを返す */
        async login(loginId: string, password: string): Promise<true | string> {
            try {
                const { data } = await axios.post<{ user: AuthUser }>("/api/login", { login_id: loginId, password });
                if (!data.user || !isUserRole(data.user.role)) {
                    await axios.post("/api/logout");
                    this.user = null;
                    this.initialized = true;
                    return LOGIN_FAILED_MESSAGE;
                }
                this.user = data.user;
                this.initialized = true;
                return true;
            } catch (error) {
                this.user = null;
                // 続けて失敗してロックされたときは、解除される時刻と問い合わせ先をサーバーのメッセージで伝える
                const message: unknown = axios.isAxiosError(error) && error.response?.status === 429 ? error.response.data?.message : null;
                return typeof message === "string" && message !== "" ? message : LOGIN_FAILED_MESSAGE;
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
