import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import FileManagementPage from "@/pages/FileManagementPage.vue";
import { useSurveysStore } from "@/stores/surveys";
import type { SurveyFile } from "@/types";

const DAY_MS = 24 * 60 * 60 * 1000;

function file(id: string, daysAgo: number, sizeKb = 100): SurveyFile {
    return { id, type: "倉庫毎の在庫数レポート", fileName: `${id}.csv`, uploadedAt: new Date(Date.now() - daysAgo * DAY_MS).toISOString(), sizeKb };
}

describe("FileManagementPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useSurveysStore().surveys = [
            { id: "recent", executedAt: "2026-10-16T00:00:00Z", importSettingName: null, products: [], files: [file("fresh", 0, 1024), file("week", 23, 512)] },
            { id: "old", executedAt: "2026-09-01T00:00:00Z", importSettingName: null, products: [], files: [file("expired", 31)] },
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
});
