import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { AmazonInventoryImport } from "@/api/amazonInventoryImports";
import type { CrossWalkerConnectionStatus } from "@/api/integrations";
import IntegrationStatus from "@/components/IntegrationStatus.vue";
import { useAuthStore } from "@/stores/auth";
import { useSurveysStore } from "@/stores/surveys";
import type { Survey } from "@/types";
import { countExpiringFiles, fileRemainingDays } from "@/utils/files";
import { toSurveySummary } from "@/utils/stock";

const { fetchAmazonInventoryImports } = vi.hoisted(() => ({ fetchAmazonInventoryImports: vi.fn() }));

const { fetchIntegrationStatus } = vi.hoisted(() => ({ fetchIntegrationStatus: vi.fn() }));

vi.mock("@/api/amazonInventoryImports", () => ({ fetchAmazonInventoryImports }));
vi.mock("@/api/integrations", () => ({ fetchIntegrationStatus }));

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
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
        fetchAmazonInventoryImports.mockReset();
        fetchIntegrationStatus.mockReset();
        fetchIntegrationStatus.mockResolvedValue({ crossWalker: null });
        vi.useFakeTimers({ toFake: ["setInterval", "clearInterval"] });
    });

    afterEach(() => vi.useRealTimers());

    it("does not display or fetch integration status for a viewer", async () => {
        useAuthStore().user = { id: "2", loginId: "viewer", role: "viewer" };
        const wrapper = mount(IntegrationStatus);
        await flushPromises();

        expect(wrapper.find('[aria-label="連携ステータス"]').exists()).toBe(false);
        expect(fetchAmazonInventoryImports).not.toHaveBeenCalled();
        expect(fetchIntegrationStatus).not.toHaveBeenCalled();
    });

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

    it.each<[CrossWalkerConnectionStatus, string]>([
        [{ status: "succeeded", checkedAt: "2026-10-16T00:15:00Z", message: null }, "ok"],
        [{ status: "failed", checkedAt: "2026-10-16T00:15:00Z", message: "CrossWalker APIのAPIキーが正しくありません。" }, "error"],
    ])("shows the latest CrossWalker connection as a lamp with its time", async (crossWalker, state) => {
        fetchAmazonInventoryImports.mockResolvedValue([]);
        fetchIntegrationStatus.mockResolvedValue({ crossWalker });
        const wrapper = mount(IntegrationStatus);
        await flushPromises();

        const row = wrapper.get('[data-integration="crosswalker"]');
        expect(row.get("[data-lamp-state]").attributes("data-lamp-state")).toBe(state);
        expect(row.text()).toContain("10-16 09:15");
        expect(row.attributes("title")).toBe(crossWalker.message ?? undefined);
    });

    it("shows a warning when no CrossWalker connection is recorded", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([]);
        const wrapper = mount(IntegrationStatus);
        await flushPromises();

        expect(wrapper.get('[data-integration="crosswalker"]').text()).toContain("記録なし");
        expect(wrapper.get('[data-integration="crosswalker"] [data-lamp-state]').attributes("data-lamp-state")).toBe("warn");
    });

    it("keeps the Amazon lamp when the CrossWalker status cannot be loaded", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([amazonImport({ status: "succeeded" })]);
        fetchIntegrationStatus.mockRejectedValue(new Error("network"));
        const wrapper = mount(IntegrationStatus);
        await flushPromises();

        expect(wrapper.get('[data-integration="crosswalker"]').text()).toContain("状態を取得できません");
        expect(wrapper.find('[data-integration="crosswalker"] [data-lamp-state]').exists()).toBe(false);
        expect(wrapper.get('[data-integration="amazon"] [data-lamp-state]').attributes("data-lamp-state")).toBe("ok");
    });

    it("warns about saved files close to expiry", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([]);
        const uploadedAt = new Date(Date.now() - 25 * 24 * 60 * 60 * 1000).toISOString();
        useSurveysStore().summaries = [toSurveySummary(surveyWithFile(uploadedAt))];
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
        expect(fetchIntegrationStatus).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(5 * 60 * 1000);
        expect(fetchAmazonInventoryImports).toHaveBeenCalledTimes(2);
        expect(fetchIntegrationStatus).toHaveBeenCalledTimes(2);

        wrapper.unmount();
        vi.advanceTimersByTime(10 * 60 * 1000);
        expect(fetchAmazonInventoryImports).toHaveBeenCalledTimes(2);
        expect(fetchIntegrationStatus).toHaveBeenCalledTimes(2);
    });
});
