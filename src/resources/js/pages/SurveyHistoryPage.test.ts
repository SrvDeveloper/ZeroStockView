import axios from "axios";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SurveyHistoryPage from "@/pages/SurveyHistoryPage.vue";
import { useAuthStore } from "@/stores/auth";
import { useSurveysStore } from "@/stores/surveys";
import type { SurveySummary } from "@/types";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({ useRouter: () => ({ push }) }));

function survey(id: string, day: number, fileName = "boss.csv"): SurveySummary {
    return {
        id,
        executedAt: new Date(2026, 9, day, 9).toISOString(),
        createdAt: new Date(2026, 9, day, 9, 12).toISOString(),
        importSettingName: null,
        amazonSource: "sp_api",
        amazonInventoryStatus: "complete",
        files: [{ id: `${id}-f`, type: "倉庫毎の在庫数レポート", fileName, uploadedAt: new Date().toISOString(), sizeKb: 1 }],
        productCount: 0,
        skuCount: 0,
        allZeroCount: 0,
        partialZeroCount: 0,
    };
}

describe("SurveyHistoryPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
        push.mockReset();
        useSurveysStore().summaries = [survey("older", 14, "boss_1014.csv"), survey("latest", 16, "boss_1016.csv")];
    });

    it("opens on the month of the latest survey with that day selected", () => {
        const wrapper = mount(SurveyHistoryPage);

        expect(wrapper.get("[data-calendar-month]").text()).toBe("2026年10月");
        expect(wrapper.get('[data-day="2026-10-16"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.get("[data-survey-detail]").text()).toContain("10月16日（金）の調査");
        expect(wrapper.get("[data-survey-detail]").text()).toContain("最新");
        expect(wrapper.get("[data-survey-detail]").text()).toContain("boss_1016.csv");
        expect(wrapper.get("[data-survey-detail]").text()).toContain("ファイルなし");
    });

    it("shows another day in the detail panel and opens its result", async () => {
        const wrapper = mount(SurveyHistoryPage);

        await wrapper.get('[data-day="2026-10-14"]').trigger("click");
        expect(wrapper.get("[data-survey-detail]").text()).toContain("10月14日（水）の調査");
        expect(wrapper.get("[data-survey-detail]").text()).not.toContain("最新");

        const openButton = wrapper
            .get("[data-survey-detail]")
            .findAll("button")
            .find((button) => button.text() === "結果を開く")!;
        await openButton.trigger("click");
        expect(push).toHaveBeenCalledWith({ name: "survey-result", params: { id: "older" } });
    });

    it("moves between months", async () => {
        const wrapper = mount(SurveyHistoryPage);

        await wrapper.get('[aria-label="次の月"]').trigger("click");
        expect(wrapper.get("[data-calendar-month]").text()).toBe("2026年11月");
        await wrapper.get('[aria-label="前の月"]').trigger("click");
        await wrapper.get('[aria-label="前の月"]').trigger("click");
        expect(wrapper.get("[data-calendar-month]").text()).toBe("2026年9月");
    });

    it("deletes the selected day after confirmation and selects the latest remaining survey", async () => {
        const surveys = useSurveysStore();
        const removeSurvey = vi.spyOn(surveys, "removeSurvey").mockImplementation(async (id: string) => {
            surveys.summaries = surveys.summaries.filter((item) => item.id !== id);
        });
        const wrapper = mount(SurveyHistoryPage, { attachTo: document.body });

        const deleteButton = wrapper
            .get("[data-survey-detail]")
            .findAll("button")
            .find((button) => button.text().includes("この日の調査を削除"))!;
        await deleteButton.trigger("click");
        const confirm = [...document.body.querySelectorAll("button")].find((button) => button.textContent?.trim() === "削除する")!;
        confirm.click();
        await flushPromises();

        expect(removeSurvey).toHaveBeenCalledWith("latest");
        expect(wrapper.get("[data-survey-detail]").text()).toContain("10月14日（水）の調査");
        wrapper.unmount();
    });

    it("switches to the list view", async () => {
        const wrapper = mount(SurveyHistoryPage);

        await wrapper.get('[data-history-view] [data-value="list"]').trigger("click");

        expect(wrapper.find("[data-calendar-month]").exists()).toBe(false);
        expect(wrapper.findAll("[data-survey-id]").map((row) => row.attributes("data-survey-id"))).toEqual(["latest", "older"]);
    });

    it.each(["editor", "viewer"] as const)("keeps downloads visible without delete controls for %s", async (role) => {
        useAuthStore().user = { id: "2", loginId: role, role };
        const wrapper = mount(SurveyHistoryPage);

        expect(wrapper.get('[data-file-entry="倉庫毎の在庫数レポート"] button').attributes("disabled")).toBeUndefined();
        expect(wrapper.get('[data-testid="survey-files-zip"]').text()).toContain("一括ダウンロード");
        expect(wrapper.get("[data-survey-detail]").text()).toContain("Excel出力");
        expect(wrapper.text()).not.toContain("この日の調査を削除");

        await wrapper.get('[data-history-view] [data-value="list"]').trigger("click");
        expect(wrapper.find('[data-survey-id="latest"] [aria-label="削除"]').exists()).toBe(false);
        expect(wrapper.find('[data-survey-id="latest"] [aria-label="Excel出力"]').exists()).toBe(true);
        expect(wrapper.get('[data-survey-id="latest"]').text()).not.toContain("Excel出力");
    });

    it("lets a viewer export the survey Excel file from history", async () => {
        useAuthStore().user = { id: "2", loginId: "viewer", role: "viewer" };
        const get = vi.spyOn(axios, "get").mockResolvedValue({ data: new Blob(["excel"]) });
        const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
        vi.spyOn(URL, "createObjectURL").mockReturnValue("blob:excel");
        const wrapper = mount(SurveyHistoryPage);

        const exportButton = wrapper
            .get("[data-survey-detail]")
            .findAll("button")
            .find((button) => button.text().includes("Excel出力"))!;
        await exportButton.trigger("click");
        await flushPromises();

        expect(get).toHaveBeenCalledWith("/api/surveys/latest/export", { responseType: "blob" });
        expect(click).toHaveBeenCalledOnce();
    });
});
