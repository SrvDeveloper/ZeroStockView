import axios from "axios";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SurveyFilesZipButton from "@/components/survey/SurveyFilesZipButton.vue";
import { useUiStore } from "@/stores/ui";
import type { Survey } from "@/types";

const DAY_MS = 24 * 60 * 60 * 1000;

function survey(daysAgo: number | null = 0): Survey {
    return {
        id: "1",
        executedAt: "2026-10-16T01:00:00Z",
        importSettingName: null,
        products: [],
        files: daysAgo === null ? [] : [{ id: "file-1", type: "倉庫毎の在庫数レポート", fileName: "boss.csv", uploadedAt: new Date(Date.now() - daysAgo * DAY_MS).toISOString(), sizeKb: 1 }],
    };
}

describe("SurveyFilesZipButton", () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        setActivePinia(createPinia());
    });

    it("downloads the saved source files as one ZIP", async () => {
        const get = vi.spyOn(axios, "get").mockResolvedValue({ data: new Blob(["zip"]) });
        const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
        vi.spyOn(URL, "createObjectURL").mockReturnValue("blob:zip");
        const wrapper = mount(SurveyFilesZipButton, { props: { survey: survey() } });

        await wrapper.get('[data-testid="survey-files-zip"]').trigger("click");
        await flushPromises();

        expect(get).toHaveBeenCalledWith("/api/surveys/1/files/download", { responseType: "blob" });
        expect(click).toHaveBeenCalledOnce();
    });

    it.each([
        ["no files", null],
        ["only expired files", 31],
    ])("is disabled when there are %s", (_, daysAgo) => {
        const wrapper = mount(SurveyFilesZipButton, { props: { survey: survey(daysAgo) } });

        const button = wrapper.get('[data-testid="survey-files-zip"]');
        expect(button.attributes("disabled")).toBeDefined();
        expect(button.attributes("title")).toBe("ダウンロードできる取込ファイルがありません");
    });

    it("reports a missing ZIP in a toast and stays disabled", async () => {
        vi.spyOn(axios, "get").mockRejectedValue({ isAxiosError: true, response: { status: 404 } });
        const wrapper = mount(SurveyFilesZipButton, { props: { survey: survey() } });

        await wrapper.get('[data-testid="survey-files-zip"]').trigger("click");
        await flushPromises();

        expect(useUiStore().toasts.map((toast) => [toast.type, toast.message])).toEqual([["error", "一括ダウンロードできるファイルが見つかりません。"]]);
        expect(wrapper.get('[data-testid="survey-files-zip"]').attributes("disabled")).toBeDefined();
    });
});
