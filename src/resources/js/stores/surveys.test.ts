import axios from "axios";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useSurveysStore } from "@/stores/surveys";
import type { Survey, SurveySummary } from "@/types";

const ZERO = { amazonOwn: 0, amazonFba: 0, bossOwn: 0, bossRfc: 0, freeStock: 0, ecStock: 0 };

function detail(id: string, overrides: Partial<Survey> = {}): Survey {
    return {
        id,
        executedAt: "2026-10-16T00:00:00Z",
        importSettingName: null,
        amazonSource: "sp_api",
        amazonInventoryStatus: "complete",
        files: [{ id: `${id}-f`, type: "KEEP一覧表", fileName: "keep.csv", uploadedAt: "2026-10-16T00:00:00Z", sizeKb: 1 }],
        products: [{ id: `${id}-p`, productCode: "A", brand: "", category: "", parentAsin: "", memo: "", skus: [{ id: `${id}-s`, skuCode: "A-1", size: "", asin: "B1", memo: "", stock: ZERO }] }],
        ...overrides,
    };
}

function summary(id: string): SurveySummary {
    const { id: surveyId, executedAt, importSettingName, amazonSource, amazonInventoryStatus, files } = detail(id);
    return { id: surveyId, executedAt, importSettingName, amazonSource, amazonInventoryStatus, files, productCount: 1, skuCount: 1, allZeroCount: 0, partialZeroCount: 0 };
}

describe("surveys store", () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.restoreAllMocks());

    it("loads the history as summaries without SKU details", async () => {
        const get = vi.spyOn(axios, "get").mockResolvedValue({ data: { data: [summary("1")] } });
        const store = useSurveysStore();

        await store.load();

        expect(get).toHaveBeenCalledWith("/api/surveys");
        expect(store.summaries.map((item) => item.id)).toEqual(["1"]);
        expect(store.getSurvey("1")).toBeUndefined();
    });

    it("fetches a detail once and reuses it unless forced", async () => {
        const get = vi.spyOn(axios, "get").mockResolvedValue({ data: { data: detail("1") } });
        const store = useSurveysStore();

        await store.loadSurvey("1");
        await store.loadSurvey("1");
        expect(get).toHaveBeenCalledTimes(1);
        await store.refreshSurvey("1");
        expect(get).toHaveBeenCalledTimes(2);
        expect(get).toHaveBeenLastCalledWith("/api/surveys/1");
    });

    it("rebuilds the summary from a fetched detail so the history stays consistent", async () => {
        const store = useSurveysStore();
        store.summaries = [summary("1"), summary("2")];
        vi.spyOn(axios, "get").mockResolvedValue({ data: { data: detail("1", { amazonInventoryStatus: "partial" }) } });

        await store.refreshSurvey("1");

        expect(store.getSummary("1")).toMatchObject({ amazonInventoryStatus: "partial", allZeroCount: 1, skuCount: 1 });
        expect(store.summaries.map((item) => item.id)).toEqual(["1", "2"]);
    });

    it("adds an imported survey to the history and keeps its detail", async () => {
        const store = useSurveysStore();
        store.summaries = [summary("1")];
        vi.spyOn(axios, "post").mockResolvedValue({ data: { data: detail("2") } });

        await store.runImport(new FormData());

        expect(store.summaries.map((item) => item.id)).toEqual(["2", "1"]);
        expect(store.getSurvey("2")?.products).toHaveLength(1);
    });

    it("removes files and surveys from both the summary and the detail", async () => {
        const store = useSurveysStore();
        store.summaries = [summary("1")];
        store.details = { "1": detail("1") };
        vi.spyOn(axios, "delete").mockResolvedValue({});

        await store.removeFile("1", "1-f");
        expect(store.getSummary("1")?.files).toEqual([]);
        expect(store.getSurvey("1")?.files).toEqual([]);

        await store.removeSurvey("1");
        expect(store.summaries).toEqual([]);
        expect(store.getSurvey("1")).toBeUndefined();
    });
});
