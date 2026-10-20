/// <reference types="vite/client" />
import { describe, expect, it } from "vitest";
import { ICON_FONT_URL, ICON_NAMES } from "@/constants/icons";

/** 画面のソース（.vue と .ts）の中身。テストとアイコンの一覧そのものは除く */
const sources = import.meta.glob<string>(["../**/*.vue", "../**/*.ts", "!../**/*.test.ts", "!./icons.ts"], { query: "?raw", import: "default", eager: true });

describe("icon names", () => {
    it("keeps the list sorted and without duplicates, as Google Fonts requires", () => {
        expect([...ICON_NAMES]).toEqual([...new Set(ICON_NAMES)].sort());
    });

    it("loads only the listed icons with the axis values AppIcon uses", () => {
        const url = new URL(ICON_FONT_URL);

        expect(url.searchParams.get("family")).toBe("Material Symbols Outlined:opsz,wght,FILL,GRAD@24,400,0..1,0");
        expect(url.searchParams.get("icon_names")).toBe(ICON_NAMES.join(","));
        expect(url.searchParams.get("display")).toBe("block");
    });

    it("lists no icon that the screens no longer use", () => {
        const text = Object.values(sources).join("\n");

        expect(Object.keys(sources).length).toBeGreaterThan(50);
        expect(ICON_NAMES.filter((name) => !new RegExp(`["']${name}["']`).test(text))).toEqual([]);
    });
});
