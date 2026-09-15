import { describe, expect, it } from "vitest";
import { beginPageLoadingTask, isPageLoading, setRouteLoading } from "./loading";

describe("page loading state", () => {
    it("tracks route navigation", () => {
        setRouteLoading(true);
        expect(isPageLoading.value).toBe(true);

        setRouteLoading(false);
        expect(isPageLoading.value).toBe(false);
    });

    it("keeps loading until every background task has finished", () => {
        const finishFirst = beginPageLoadingTask();
        const finishSecond = beginPageLoadingTask();

        finishFirst();
        expect(isPageLoading.value).toBe(true);

        finishSecond();
        expect(isPageLoading.value).toBe(false);

        finishSecond();
        expect(isPageLoading.value).toBe(false);
    });
});
