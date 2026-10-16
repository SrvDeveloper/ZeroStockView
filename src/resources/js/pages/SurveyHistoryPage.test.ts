import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SurveyHistoryPage from "@/pages/SurveyHistoryPage.vue";
import { useSurveysStore } from "@/stores/surveys";
import type { Survey } from "@/types";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({ useRouter: () => ({ push }) }));

function survey(id: string, day: number, fileName = "boss.csv"): Survey {
    return {
        id,
        executedAt: new Date(2026, 9, day, 9).toISOString(),
        createdAt: new Date(2026, 9, day, 9, 12).toISOString(),
        importSettingName: null,
        amazonSource: "sp_api",
        amazonInventoryStatus: "complete",
        files: [{ id: `${id}-f`, type: "倉庫毎の在庫数レポート", fileName, uploadedAt: "", sizeKb: 1 }],
        products: [],
    };
}

describe("SurveyHistoryPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        push.mockReset();
        useSurveysStore().surveys = [survey("older", 14, "boss_1014.csv"), survey("latest", 16, "boss_1016.csv")];
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
            surveys.surveys = surveys.surveys.filter((item) => item.id !== id);
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
});
