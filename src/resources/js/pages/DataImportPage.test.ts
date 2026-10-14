import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AmazonInventoryImport } from "@/api/amazonInventoryImports";
import FileDropzone from "@/components/ui/FileDropzone.vue";
import DataImportPage from "@/pages/DataImportPage.vue";
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

function mountPage() {
    setActivePinia(createPinia());
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
    return [
        new File([""], "FBA在庫管理レポート.csv"),
        new File([""], "KEEP一覧表.csv"),
        new File([""], "在庫一覧照会表.csv"),
        new File([""], "在庫商品レポート.txt"),
        new File([""], "倉庫毎の在庫数レポート.csv"),
    ];
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
            expect(wrapper.get('[data-testid="amazon-import-status"]').text()).toContain("対象 SKU: 2 件");
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

    it("places the settings button next to the selector without the old target summary", async () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain("利用する取込設定");
        expect(wrapper.text()).toContain("設定を変更");
        expect(wrapper.text()).not.toContain("の取込対象");

        await wrapper.get('[data-testid="settings-change-button"]').trigger("click");

        expect(push).toHaveBeenCalledWith({ name: "import-settings" });
    });

    it("assigns all import files by exact file name from a selected folder", async () => {
        const wrapper = mountPage();
        const selectedFiles = validImportFiles();

        await selectFolder(wrapper, selectedFiles);

        const selectedNames = wrapper.findAllComponents(FileDropzone).map((dropzone) => (dropzone.props("modelValue") as File | null)?.name);
        expect(selectedNames).toEqual(["在庫商品レポート.txt", "FBA在庫管理レポート.csv", "倉庫毎の在庫数レポート.csv", "KEEP一覧表.csv", "在庫一覧照会表.csv"]);
        expect(wrapper.text()).toContain("5 / 5");
        expect(wrapper.text()).not.toContain("フォルダを確認してください");
    });

    it("assigns all import files dropped together", async () => {
        const wrapper = mountPage();
        const dropzone = wrapper.get('[data-testid="bulk-file-dropzone"]');

        await dropzone.trigger("dragenter");
        expect(dropzone.classes()).toContain("border-primary-500");

        await dropzone.trigger("drop", { dataTransfer: { files: validImportFiles() } });

        expect(wrapper.text()).toContain("5 / 5");
        expect(dropzone.classes()).not.toContain("border-primary-500");
    });

    it("runs the import with the setting selected by the user", async () => {
        const wrapper = mountPage();
        const settings = useImportSettingsStore();
        const runImport = vi.spyOn(useSurveysStore(), "runImport").mockResolvedValue({
            id: "10",
            executedAt: "2026-09-16T00:00:00Z",
            products: [],
            importSettingName: "サングラス",
            files: [],
        });

        await wrapper.get("select").setValue("2");
        await selectFolder(wrapper, validImportFiles());
        const runButton = wrapper.findAll("button").at(-1)!;
        await runButton.trigger("click");
        await flushPromises();

        expect(settings.selectedSettingId).toBe("2");
        expect(wrapper.text()).toContain("サングラス");
        expect(runImport).toHaveBeenCalledTimes(1);
        const formData = runImport.mock.calls[0]![0];
        expect(formData.get("import_setting_id")).toBe("2");
    });

    it("reports missing and duplicate files without retaining invalid selections", async () => {
        const wrapper = mountPage();
        const selectedFiles = [
            new File([""], "在庫商品レポート.txt"),
            new File([""], "FBA在庫管理レポート.csv"),
            new File([""], "KEEP一覧表.csv"),
            new File([""], "KEEP一覧表.csv"),
            new File([""], "在庫一覧照会表.csv"),
        ];

        await selectFolder(wrapper, selectedFiles);

        expect(wrapper.text()).toContain("見つからないファイル: 倉庫毎の在庫数レポート.csv");
        expect(wrapper.text()).toContain("同名ファイルが複数あります: KEEP一覧表.csv");
        const dropzones = wrapper.findAllComponents(FileDropzone);
        expect(dropzones[2]!.props("modelValue")).toBeNull();
        expect(dropzones[3]!.props("modelValue")).toBeNull();
        expect(wrapper.text()).toContain("3 / 5");
    });
});
