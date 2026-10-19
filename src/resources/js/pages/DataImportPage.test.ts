import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AmazonInventoryImport } from "@/api/amazonInventoryImports";
import DataImportPage from "@/pages/DataImportPage.vue";
import type { UserRole } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";

const { push, fetchAmazonInventoryImports, startAmazonInventoryImport } = vi.hoisted(() => ({
    push: vi.fn(),
    fetchAmazonInventoryImports: vi.fn(),
    startAmazonInventoryImport: vi.fn(),
}));

vi.mock("@/api/amazonInventoryImports", () => ({ fetchAmazonInventoryImports, startAmazonInventoryImport }));

vi.mock("vue-router", () => {
    return { useRouter: () => ({ push }) };
});

function mountPage(role: UserRole = "admin") {
    setActivePinia(createPinia());
    useAuthStore().user = { id: "1", loginId: role, role };
    const settings = useImportSettingsStore();
    settings.settings = [
        {
            id: "1",
            name: "売上TOP20",
            productCodes: ["A-1001"],
            products: [],
            lastSyncedAt: null,
        },
        {
            id: "2",
            name: "サングラス",
            productCodes: ["B-2001", "B-2002"],
            products: [],
            lastSyncedAt: null,
        },
    ];
    settings.selectedSettingId = "1";
    settings.loaded = true;

    return mount(DataImportPage, {
        global: {
            stubs: { RouterLink: true },
        },
    });
}

async function selectFolder(wrapper: VueWrapper, selectedFiles: File[]) {
    const input = wrapper.get("input[webkitdirectory]");
    Object.defineProperty(input.element, "files", { configurable: true, value: selectedFiles });
    await input.trigger("change");
}

function validImportFiles(): File[] {
    return [new File([""], "stock-a.csv"), new File([""], "stock-b.csv"), new File([""], "stock-c.csv")];
}

function amazonImport(status: AmazonInventoryImport["status"]): AmazonInventoryImport {
    return {
        id: "7",
        status,
        triggerSource: "manual",
        source: "sp_api",
        createdAt: "2026-10-14T00:00:00Z",
        startedAt: null,
        completedAt: null,
        basisAt: status === "succeeded" ? "2026-10-14T01:00:00Z" : null,
        targetSkuCount: status === "succeeded" ? 2 : null,
        ownRowCount: null,
        fbaRowCount: null,
        matchedOwnCount: status === "succeeded" ? 1 : null,
        matchedFbaCount: status === "succeeded" ? 2 : null,
        errorMessage: status === "failed" ? "FBAレポートの生成に失敗しました。" : null,
    };
}

describe("DataImportPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
        push.mockReset();
        fetchAmazonInventoryImports.mockReset().mockResolvedValue([]);
        startAmazonInventoryImport.mockReset().mockResolvedValue(amazonImport("queued"));
    });

    it("queues an Amazon import and prevents a second click while it is active", async () => {
        const wrapper = mountPage();
        await flushPromises();

        const button = wrapper.get('[data-testid="amazon-import-start"]');
        await button.trigger("click");
        await flushPromises();

        expect(startAmazonInventoryImport).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("受付");
        expect(button.attributes("disabled")).toBeDefined();
        wrapper.unmount();
    });

    it("allows an editor to import CSV files without offering manual Amazon import", async () => {
        const wrapper = mountPage("editor");
        const runImport = vi.spyOn(useSurveysStore(), "runImport").mockResolvedValue({ id: "10", executedAt: "2026-10-16T00:00:00Z", products: [], importSettingName: null, files: [] });
        await flushPromises();

        expect(wrapper.find('[data-testid="amazon-import-start"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain("手動でも取得できます");
        expect(fetchAmazonInventoryImports).toHaveBeenCalledOnce();

        await selectFolder(wrapper, [new File([""], "stock.csv")]);
        await wrapper.get('[data-testid="run-import"]').trigger("click");
        await flushPromises();

        expect(runImport).toHaveBeenCalledOnce();
        expect(startAmazonInventoryImport).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it("does not fetch import data when a viewer mounts the page directly", async () => {
        const wrapper = mountPage("viewer");
        await flushPromises();

        expect(wrapper.text()).toBe("");
        expect(fetchAmazonInventoryImports).not.toHaveBeenCalled();
        expect(startAmazonInventoryImport).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it("restores a failed Amazon import after reload and allows retry", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([amazonImport("failed")]);
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("FBAレポートの生成に失敗しました。");
        await wrapper.get('[data-testid="amazon-import-start"]').trigger("click");
        await flushPromises();

        expect(startAmazonInventoryImport).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("受付");
        wrapper.unmount();
    });

    it("identifies a scheduled Amazon import in the history", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([{ ...amazonImport("succeeded"), triggerSource: "scheduled" }]);

        const wrapper = mount(DataImportPage);
        await flushPromises();

        expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("定期実行");
    });

    it("refreshes a running Amazon import until it succeeds", async () => {
        fetchAmazonInventoryImports.mockResolvedValueOnce([amazonImport("running")]).mockResolvedValueOnce([amazonImport("succeeded")]);
        let poll: (() => void) | undefined;
        const interval = vi.spyOn(window, "setInterval").mockImplementation((callback) => {
            poll = callback as () => void;
            return 1;
        });
        const clear = vi.spyOn(window, "clearInterval").mockImplementation(() => {});
        try {
            const wrapper = mountPage();
            await flushPromises();
            expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("処理中");
            expect(wrapper.get('[data-testid="amazon-import-start"]').attributes("disabled")).toBeDefined();

            poll?.();
            await flushPromises();
            expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("成功");
            expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("在庫基準日時");
            expect(wrapper.text()).not.toContain("対象 SKU");
            expect(wrapper.text()).not.toContain("ASIN が登録されていない");
            expect(wrapper.get('[data-testid="amazon-import-start"]').attributes("disabled")).toBeUndefined();
            wrapper.unmount();
        } finally {
            interval.mockRestore();
            clear.mockRestore();
        }
    });

    it("shows the active run when another request already started an Amazon import", async () => {
        fetchAmazonInventoryImports.mockResolvedValueOnce([]).mockResolvedValueOnce([amazonImport("running")]);
        startAmazonInventoryImport.mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { message: "Amazon在庫取込はすでに実行中です。" } } });
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="amazon-import-start"]').trigger("click");
        await flushPromises();

        expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("処理中");
        expect(wrapper.text()).toContain("Amazon在庫取込はすでに実行中です。");
        expect(wrapper.get('[data-testid="amazon-import-start"]').attributes("disabled")).toBeDefined();
        wrapper.unmount();
    });

    it("shows the group edit action without an import result group selector", async () => {
        const wrapper = mountPage();

        expect(wrapper.text()).not.toContain("取込後に表示する品番グループ");
        expect(wrapper.find("select").exists()).toBe(false);
        expect(wrapper.text()).toContain("グループを編集");

        await wrapper.get('[data-testid="settings-change-button"]').trigger("click");

        expect(push).toHaveBeenCalledWith({ name: "import-settings" });
    });

    it("accepts arbitrary file names from a folder without type-specific upload slots", async () => {
        const wrapper = mountPage();
        const selectedFiles = validImportFiles();

        await selectFolder(wrapper, selectedFiles);

        expect(wrapper.get('[data-testid="selected-files"]').text()).toContain("stock-a.csv");
        expect(wrapper.get('[data-testid="selected-files"]').text()).toContain("stock-c.csv");
        expect(wrapper.text()).toContain("添付ファイル: 3件");
        expect(wrapper.text()).not.toContain("ファイルを確認してください");
        expect(wrapper.text()).not.toContain("種類別");
    });

    it("assigns all import files dropped together", async () => {
        const wrapper = mountPage();
        const dropzone = wrapper.get('[data-testid="bulk-file-dropzone"]');

        await dropzone.trigger("dragenter");
        expect(dropzone.attributes("data-dragging")).toBe("true");

        await dropzone.trigger("drop", { dataTransfer: { files: validImportFiles() } });

        expect(wrapper.text()).toContain("添付ファイル: 3件");
        expect(dropzone.attributes("data-dragging")).toBeUndefined();
    });

    it("runs the import without a selected group and opens all results", async () => {
        const wrapper = mountPage();
        const settings = useImportSettingsStore();
        settings.selectedSettingId = null;
        const runImport = vi.spyOn(useSurveysStore(), "runImport").mockResolvedValue({
            id: "10",
            executedAt: "2026-09-16T00:00:00Z",
            products: [],
            importSettingName: null,
            files: [],
        });

        await selectFolder(wrapper, validImportFiles());
        const runButton = wrapper.get('[data-testid="run-import"]');
        expect(runButton.attributes("disabled")).toBeUndefined();
        await runButton.trigger("click");
        await flushPromises();

        expect(runImport).toHaveBeenCalledTimes(1);
        const formData = runImport.mock.calls[0]![0];
        expect(formData.has("import_setting_id")).toBe(false);
        expect(push).toHaveBeenCalledWith({ name: "survey-result", params: { id: "10" } });
        expect(formData.get("files[0]")).toBeInstanceOf(File);
        expect(formData.get("files[1]")).toBeInstanceOf(File);
        expect(formData.get("files[2]")).toBeInstanceOf(File);
        expect(formData.has("boss")).toBe(false);
        expect(formData.has("amazon_own")).toBe(false);
        expect(formData.has("amazon_fba")).toBe(false);
    });

    it("can run with one file and lets the user remove it", async () => {
        const wrapper = mountPage();
        await selectFolder(wrapper, [new File([""], "renamed.csv")]);

        expect(wrapper.text()).toContain("添付ファイル: 1件");
        expect(wrapper.get('[data-testid="run-import"]').attributes("disabled")).toBeUndefined();
        await wrapper.get('[aria-label="renamed.csvを外す"]').trigger("click");
        expect(wrapper.text()).toContain("添付ファイル: 0件");
        expect(wrapper.get('[data-testid="run-import"]').attributes("disabled")).toBeDefined();
    });

    it("rejects more than three selected files without replacing the current selection", async () => {
        const wrapper = mountPage();
        await selectFolder(wrapper, [new File([""], "first.csv")]);
        await selectFolder(wrapper, validImportFiles());

        expect(wrapper.text()).toContain("ファイルは3件まで添付できます");
        expect(wrapper.text()).toContain("添付ファイル: 1件");
        expect(wrapper.get('[data-testid="selected-files"]').text()).toContain("first.csv");
    });

    it("shows a card only for each attached file", async () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="selected-files"]').exists()).toBe(false);

        await selectFolder(wrapper, [new File([""], "first.csv"), new File([""], "second.csv")]);

        const cards = wrapper.findAll("[data-file-card]");
        expect(cards).toHaveLength(2);
        expect(cards[0]!.text()).toContain("first.csv");
        expect(cards[1]!.text()).toContain("second.csv");
        expect(wrapper.text()).not.toContain("添付 1");
        expect(wrapper.text()).not.toContain("未添付");
    });

    it("lists recent Amazon imports with their results", async () => {
        fetchAmazonInventoryImports.mockResolvedValue([amazonImport("succeeded"), { ...amazonImport("failed"), id: "6" }, { ...amazonImport("running"), id: "5" }]);
        const wrapper = mountPage();
        await flushPromises();

        const history = wrapper.get("[data-amazon-history]");
        expect(history.findAll("[data-lamp-state]").map((lamp) => lamp.attributes("data-lamp-state"))).toEqual(["ok", "error", "warn"]);
        expect(history.text()).toContain("FBAレポートの生成に失敗しました。");
    });
});
