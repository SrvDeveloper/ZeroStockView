import axios from "axios";
import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import router from "./router";
import { beginPageLoadingTask } from "./router/loading";
import piniaPluginPersistedstate from "pinia-plugin-persistedstate";
import { useAuthStore } from "@/stores/auth";
import { ICON_FONT_URL } from "@/constants/icons";
import { EDITOR_ROLES, canAccess } from "@/constants/roles";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import "@css/app.css";

axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
axios.defaults.headers.common["Accept"] = "application/json";
axios.defaults.headers.common["X-CSRF-TOKEN"] = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "";

// アイコン用フォントは、画面で使うアイコンだけを読み込む（一覧は constants/icons.ts）
const iconFont = document.createElement("link");
iconFont.rel = "stylesheet";
iconFont.href = ICON_FONT_URL;
document.head.append(iconFont);

const pinia = createPinia();
pinia.use(piniaPluginPersistedstate);

const app = createApp(App);
const finishBootstrapLoading = beginPageLoadingTask();
app.use(pinia);
app.use(router);
app.mount("#app");

// アプリを先に描画してローディングを表示し、認証と初期データの準備完了後に解除する。
void (async () => {
    try {
        await router.isReady();
        const auth = useAuthStore(pinia);
        if (auth.isLoggedIn) {
            await Promise.allSettled([useSurveysStore(pinia).load(), ...(canAccess(auth.role, EDITOR_ROLES) ? [useImportSettingsStore(pinia).load()] : [])]);
        }
    } finally {
        finishBootstrapLoading();
    }
})();
