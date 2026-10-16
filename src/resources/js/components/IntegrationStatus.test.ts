import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { AmazonInventoryImport } from "@/api/amazonInventoryImports";
import IntegrationStatus from "@/components/IntegrationStatus.vue";
import { useSurveysStore } from "@/stores/surveys";
import type { Survey } from "@/types";
import { countExpiringFiles, fileRemainingDays } from "@/utils/files";

const { fetchAmazonInventoryImports } = vi.hoisted(() => ({ fetchAmazonInventoryImports: vi.fn() }));

vi.mock("@/api/amazonInventoryImports", () => ({ fetchAmazonInventoryImports }));

function amazonImport(overrides: Partial<AmazonInventoryImport>): AmazonInventoryImport {
    return {
        id: "1",
        status: "succeeded",
        triggerSource: "scheduled",
        source: "sp_api",
        createdAt: "2026-10-16T00:00:00Z",
        startedAt: null,
        completedAt: "2026-10-16T00:05:00Z",
        basisAt: "2026-10-15T23:52:00Z",
        targetSkuCount: 1,
        ownRowCount: 1,
        fbaRowCount: 1,
        matchedOwnCount: 1,
        matchedFbaCount: 1,
        errorMessage: null,
        ...overrides,
    };
}

function surveyWithFile(uploadedAt: string): Survey {
    return { id: uploadedAt, executedAt: uploadedAt, products: [], importSettingName: null, files: [{ id: uploadedAt, type: "KEEP一覧表", fileName: "a.csv", uploadedAt, sizeKb: 1 }] };
}

describe("file expiry", () => {
    const now = new Date("2026-10-16T09:00:00+09:00");

    it("counts remaining days rounded up", () => {
        expect(fileRemainingDays("2026-10-16T09:00:00+09:00", now)).toBe(30);
        expect(fileRemainingDays("2026-09-23T09:00:00+09:00", now)).toBe(7);
        expect(fileRemainingDays("2026-09-16T09:00:00+09:00", now)).toBe(0);
    });

    it("counts only files expiring within seven days", () => {
        const surveys = [
            surveyWithFile("2026-09-23T09:00:00+09:00"),
            surveyWithFile("2026-09-22T09:00:00+09:00"),
            surveyWithFile("2026-10-10T09:00:00+09:00"),
            surveyWithFile("2026-09-01T09:00:00+09:00"),
        ];
        expect(countExpiringFiles(surveys, now)).toBe(2);
    });
});

describe("IntegrationStatus", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        fetchAmazonInventoryImports.mockReset();
        vi.useFakeTimers({ toFake: ["setInterval", "clearInterval"] });
    });

    afterEach(() => vi.useRealTimers());

    it.each([
        [amazonImport({ status: "succeeded" }), "ok"],
        [amazonImport({ status: "running" }), "warn"],
        [amazonImport({ status: "queued" }), "warn"],
        [amazonImport({ status: "failed" }), "error"],
    ])("shows the latest Amazon import as a lamp", async (latest, state) => {
        fetchAmazonInventoryImports.mockResolvedValue([latest, amazonImport({ id: "0", status: "failed" })]);
        const wrapper = mount(IntegrationStatus);
        await flushPromises();

        expect(wrapper.get('[data-integration="amazon"] [data-lamp-state]').attributes("data-lamp-state")).toBe(state);
    });

    it("shows a warning when no Amazon import exists", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([]);
        const wrapper = mount(IntegrationStatus);
        await flushPromises();

        expect(wrapper.get('[data-integration="amazon"]').text()).toContain("未取得");
        expect(wrapper.get('[data-integration="amazon"] [data-lamp-state]').attributes("data-lamp-state")).toBe("warn");
    });

    it("explains when the Amazon status cannot be loaded", async () => {
        fetchAmazonInventoryImports.mockRejectedValue(new Error("network"));
        const wrapper = mount(IntegrationStatus);
        await flushPromises();

        expect(wrapper.get('[data-integration="amazon"]').text()).toContain("状態を取得できません");
        expect(wrapper.find('[data-integration="amazon"] [data-lamp-state]').exists()).toBe(false);
    });

    it("warns about saved files close to expiry", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([]);
        const uploadedAt = new Date(Date.now() - 25 * 24 * 60 * 60 * 1000).toISOString();
        useSurveysStore().surveys = [surveyWithFile(uploadedAt)];
        const wrapper = mount(IntegrationStatus);
        await flushPromises();

        expect(wrapper.get('[data-integration="files"]').text()).toContain("1件 期限間近");
        expect(wrapper.get('[data-integration="files"] [data-lamp-state]').attributes("data-lamp-state")).toBe("warn");
    });

    it("refreshes every five minutes and stops after unmounting", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([]);
        const wrapper = mount(IntegrationStatus);
        await flushPromises();
        expect(fetchAmazonInventoryImports).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(5 * 60 * 1000);
        expect(fetchAmazonInventoryImports).toHaveBeenCalledTimes(2);

        wrapper.unmount();
        vi.advanceTimersByTime(10 * 60 * 1000);
        expect(fetchAmazonInventoryImports).toHaveBeenCalledTimes(2);
    });
});
