import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import { google } from "laravel-vite-plugin/fonts";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.ts"],
            refresh: true,
            // ビルド時に Google Fonts から取得して自前配信する。和文は分割ファイルが多いため先読みしない
            fonts: [
                google("Noto Sans JP", { weights: [400, 500, 600, 700], preload: false, fallbacks: ["Hiragino Sans", "Yu Gothic", "sans-serif"], optimizedFallbacks: false }),
                google("IBM Plex Mono", { weights: [400, 500, 600], fallbacks: ["ui-monospace", "monospace"], optimizedFallbacks: false }),
                google("Barlow Condensed", { weights: [500, 600, 700], fallbacks: ["Arial Narrow", "sans-serif"], optimizedFallbacks: false }),
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: { usePolling: true },
        host: "127.0.0.1",
        port: 5173,
    },
    resolve: {
        alias: {
            "@": "/resources/js",
            "@css": "/resources/css",
        },
    },
});
