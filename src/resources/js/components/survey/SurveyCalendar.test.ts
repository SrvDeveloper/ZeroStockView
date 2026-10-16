import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import SurveyCalendar from "@/components/survey/SurveyCalendar.vue";
import type { Survey, SurveySkuResult } from "@/types";
import { buildCalendar, dailySurveys } from "@/utils/surveyCalendar";

const ZERO = { amazonOwn: 0, amazonFba: 0, bossOwn: 0, bossRfc: 0, freeStock: 0, ecStock: 0 };
const STOCKED = { amazonOwn: 1, amazonFba: 1, bossOwn: 1, bossRfc: 1, freeStock: 1, ecStock: 1 };

function sku(id: string, stock: SurveySkuResult["stock"]): SurveySkuResult {
    return { id, skuCode: id, colorName: "", size: "", asin: "B1", tqCode: "", tqColorNo: "", tqSize: "", memo: "", stock };
}

/** 現地時刻の 2026 年 month 月 day 日 hour 時の調査（month は 1〜12） */
function survey(id: string, month: number, day: number, allZero = 0, hour = 9): Survey {
    const skus = [...Array.from({ length: allZero }, (_, index) => sku(`${id}-z${index}`, ZERO)), sku(`${id}-s`, STOCKED)];
    return {
        id,
        executedAt: new Date(2026, month - 1, day, hour).toISOString(),
        importSettingName: null,
        amazonSource: "sp_api",
        amazonInventoryStatus: "complete",
        files: [
            { id: `${id}-f1`, type: "倉庫毎の在庫数レポート", fileName: "boss.csv", uploadedAt: "", sizeKb: 1 },
            { id: `${id}-f2`, type: "KEEP一覧表", fileName: "keep.csv", uploadedAt: "", sizeKb: 1 },
        ],
        products: [{ id: `${id}-p`, productCode: "A", brand: "", category: "", parentAsin: "", memo: "", skus }],
    };
}

const TODAY = new Date(2026, 9, 16, 12);

describe("buildCalendar", () => {
    it("starts on Sunday and pads the first week", () => {
        const days = buildCalendar(2026, 9, [], TODAY);

        expect(days.filter((day) => day.kind === "blank")).toHaveLength(4);
        expect(days.filter((day) => day.kind !== "blank")).toHaveLength(31);
        expect(days[4]).toMatchObject({ date: "2026-10-01", weekday: 4 });
        expect(days.at(-1)).toMatchObject({ date: "2026-10-31", weekday: 6 });
    });

    it("handles months that start on Sunday and leap years", () => {
        const february = buildCalendar(2026, 1, [], TODAY);
        expect(february.filter((day) => day.kind === "blank")).toHaveLength(0);
        expect(february).toHaveLength(28);

        expect(buildCalendar(2028, 1, [], TODAY).filter((day) => day.kind !== "blank")).toHaveLength(29);
    });

    it("separates days without a survey from future days", () => {
        const days = buildCalendar(2026, 9, [survey("1", 10, 1)], TODAY);
        const byDate = (date: string) => days.find((day) => day.date === date)!;

        expect(byDate("2026-10-01").kind).toBe("survey");
        expect(byDate("2026-10-02").kind).toBe("none");
        expect(byDate("2026-10-16").kind).toBe("none");
        expect(byDate("2026-10-17").kind).toBe("future");
    });

    it("uses the latest survey when one day has several", () => {
        const morning = survey("morning", 10, 5, 0, 8);
        const evening = survey("evening", 10, 5, 0, 18);

        expect(dailySurveys([evening, morning]).get("2026-10-05")?.id).toBe("evening");
        expect(buildCalendar(2026, 9, [morning, evening], TODAY).find((day) => day.date === "2026-10-05")?.survey?.id).toBe("evening");
    });

    it("flags days whose all-zero count rose since the previous survey day, skipping days without surveys", () => {
        const days = buildCalendar(2026, 9, [survey("1", 10, 1, 2), survey("3", 10, 3, 3), survey("4", 10, 4, 1), survey("5", 10, 5, 1)], TODAY);
        const byDate = (date: string) => days.find((day) => day.date === date)!;

        expect(byDate("2026-10-01")).toMatchObject({ allZeroCount: 2, increased: false });
        expect(byDate("2026-10-03")).toMatchObject({ allZeroCount: 3, increased: true });
        expect(byDate("2026-10-04").increased).toBe(false);
        expect(byDate("2026-10-05").increased).toBe(false);
        expect(byDate("2026-10-03").fileCount).toBe(2);
        expect(byDate("2026-10-03").amazonLamp).toBe("ok");
    });

    it("compares the first day of a month with the last survey of the previous month", () => {
        const days = buildCalendar(2026, 9, [survey("sep", 9, 30, 1), survey("oct", 10, 1, 2)], TODAY);

        expect(days.find((day) => day.date === "2026-10-01")?.increased).toBe(true);
    });
});

describe("SurveyCalendar", () => {
    it("lets the user pick a survey day", async () => {
        const days = buildCalendar(2026, 9, [survey("1", 10, 1, 2), survey("3", 10, 3, 3)], TODAY);
        const wrapper = mount(SurveyCalendar, { props: { days, selectedId: "1" } });

        expect(wrapper.get('[data-day="2026-10-01"]').attributes("aria-pressed")).toBe("true");
        expect(wrapper.get('[data-day="2026-10-03"] [data-all-zero]').attributes("data-increased")).toBe("true");
        expect(wrapper.get('[data-day="2026-10-02"]').text()).toContain("調査なし");

        await wrapper.get('[data-day="2026-10-03"]').trigger("click");

        expect(wrapper.emitted("select")).toEqual([["3"]]);
    });
});
