import axios from "axios";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SurveyFileDownloads from "@/components/survey/SurveyFileDownloads.vue";
import type { Survey } from "@/types";

const DAY_MS = 24 * 60 * 60 * 1000;

function survey(daysAgo = 0): Survey {
    return {
        id: "1",
        executedAt: "2026-10-16T01:00:00Z",
        importSettingName: null,
        products: [],
        files: [{ id: "file-1", type: "倉庫毎の在庫数レポート", fileName: "boss.csv", uploadedAt: new Date(Date.now() - daysAgo * DAY_MS).toISOString(), sizeKb: 1 }],
    };
}

describe("SurveyFileDownloads", () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        setActivePinia(createPinia());
    });

    it("downloads a saved source file and ZIP", async () => {
        const get = vi.spyOn(axios, "get").mockResolvedValue({ data: new Blob(["saved"]) });
        const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
        const createObjectURL = vi.spyOn(URL, "createObjectURL").mockReturnValue("blob:download");
        const wrapper = mount(SurveyFileDownloads, { props: { survey: survey() } });

        await wrapper.get('[aria-label="boss.csvをダウンロード"]').trigger("click");
        await flushPromises();
        await wrapper.get('[data-testid="survey-files-zip"]').trigger("click");
        await flushPromises();

        expect(get).toHaveBeenNthCalledWith(1, "/api/survey-files/file-1/download", { responseType: "blob" });
        expect(get).toHaveBeenNthCalledWith(2, "/api/surveys/1/files/download", { responseType: "blob" });
        expect(createObjectURL).toHaveBeenCalledTimes(2);
        expect(click).toHaveBeenCalledTimes(2);
    });

    it("disables expired and deleted files with a clear explanation", () => {
        const expired = mount(SurveyFileDownloads, { props: { survey: survey(31) } });
        expect(expired.get('[aria-label="boss.csvをダウンロード"]').attributes("disabled")).toBeDefined();
        const expiredRow = expired.get('[data-file-entry="倉庫毎の在庫数レポート"]');
        expect(expiredRow.text()).toContain("保存期限切れ");
        expect(expiredRow.find('[title="boss.csv"]').exists()).toBe(true);
        expect(expired.get('[data-testid="survey-files-zip"]').attributes("disabled")).toBeDefined();

        const deletedSurvey = survey();
        deletedSurvey.files = [];
        const deleted = mount(SurveyFileDownloads, { props: { survey: deletedSurvey } });
        expect(deleted.get('[data-file-entry="倉庫毎の在庫数レポート"]').text()).toContain("ファイルなし");
        expect(deleted.find('[aria-label="boss.csvをダウンロード"]').exists()).toBe(false);
        expect(deleted.get('[data-testid="survey-files-zip"]').attributes("disabled")).toBeDefined();
    });

    it("shows an API 404 and prevents retrying a missing file", async () => {
        vi.spyOn(axios, "get").mockRejectedValue({ isAxiosError: true, response: { status: 404 } });
        const wrapper = mount(SurveyFileDownloads, { props: { survey: survey() } });

        await wrapper.get('[aria-label="boss.csvをダウンロード"]').trigger("click");
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe("ファイルが見つかりません。保存期限が過ぎたか、削除された可能性があります。");
        expect(wrapper.get('[data-file-entry="倉庫毎の在庫数レポート"]').text()).toContain("見つかりません");
        expect(wrapper.get('[aria-label="boss.csvをダウンロード"]').attributes("disabled")).toBeDefined();
    });
});
