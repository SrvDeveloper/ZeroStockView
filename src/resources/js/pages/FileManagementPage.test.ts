import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import FileManagementPage from "@/pages/FileManagementPage.vue";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import type { SurveyFile } from "@/types";
import { toSurveySummary } from "@/utils/stock";

const DAY_MS = 24 * 60 * 60 * 1000;

function file(id: string, daysAgo: number, sizeKb = 100): SurveyFile {
    return { id, type: "倉庫毎の在庫数レポート", fileName: `${id}.csv`, uploadedAt: new Date(Date.now() - daysAgo * DAY_MS).toISOString(), sizeKb };
}

describe("FileManagementPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useSurveysStore().summaries = [
            toSurveySummary({ id: "recent", executedAt: "2026-10-16T00:00:00Z", importSettingName: null, products: [], files: [file("fresh", 0, 1024), file("week", 23, 512)] }),
            toSurveySummary({ id: "old", executedAt: "2026-09-01T00:00:00Z", importSettingName: null, products: [], files: [file("expired", 31)] }),
        ];
    });

    it("summarizes stored files and their size", () => {
        const wrapper = mount(FileManagementPage, { global: { stubs: { RouterLink: true } } });
        const summary = wrapper.get("[data-file-summary]").text();

        expect(summary).toContain("保存中のファイル 2");
        expect(summary).toContain("合計 1.5 MB");
        expect(summary).toContain("期限まで7日以内 1");
    });

    it("shows the remaining days and warns within seven days of expiry", () => {
        const wrapper = mount(FileManagementPage, { global: { stubs: { RouterLink: true } } });

        expect(wrapper.get('[data-file-id="fresh"] [data-remaining-days]').text()).toContain("残り 30 日");
        expect(wrapper.find('[data-file-id="fresh"] [data-lamp-state]').exists()).toBe(false);
        expect(wrapper.get('[data-file-id="week"] [data-remaining-days]').text()).toContain("残り 7 日");
        expect(wrapper.get('[data-file-id="week"] [data-lamp-state]').attributes("data-lamp-state")).toBe("warn");
        expect(wrapper.get('[data-file-id="fresh"]').text()).toContain("BOSS");
    });

    it("hides expired files and explains that the result remains", () => {
        const wrapper = mount(FileManagementPage, { global: { stubs: { RouterLink: true } } });

        expect(wrapper.find('[data-file-id="expired"]').exists()).toBe(false);
        expect(wrapper.get('[data-file-group="old"]').text()).toContain("取込ファイルはありません");
    });

    it("keeps the file and shows an error when deletion is denied", async () => {
        const removeFile = vi.spyOn(useSurveysStore(), "removeFile").mockRejectedValue(new Error("forbidden"));
        const wrapper = mount(FileManagementPage, { global: { stubs: { RouterLink: true, Teleport: true } } });

        await wrapper.get('[data-file-id="fresh"] [aria-label="削除"]').trigger("click");
        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("削除する"))!
            .trigger("click");
        await flushPromises();

        expect(removeFile).toHaveBeenCalledWith("recent", "fresh");
        expect(wrapper.find('[data-file-id="fresh"]').exists()).toBe(true);
        expect(useUiStore().toasts.at(-1)).toMatchObject({ type: "error", message: "ファイルの削除に失敗しました。時間をおいて再度お試しください。" });
    });

    it("keeps the files and shows an error when bulk deletion is denied", async () => {
        const removeFiles = vi.spyOn(useSurveysStore(), "removeFiles").mockRejectedValue(new Error("forbidden"));
        const wrapper = mount(FileManagementPage, { global: { stubs: { RouterLink: true, Teleport: true } } });

        await wrapper
            .get('[data-file-group="recent"]')
            .findAll("button")
            .find((button) => button.text().includes("一括削除"))!
            .trigger("click");
        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("削除する"))!
            .trigger("click");
        await flushPromises();

        expect(removeFiles).toHaveBeenCalledWith("recent");
        expect(wrapper.find('[data-file-id="fresh"]').exists()).toBe(true);
        expect(useUiStore().toasts.at(-1)).toMatchObject({ type: "error", message: "ファイルの一括削除に失敗しました。時間をおいて再度お試しください。" });
    });
});
