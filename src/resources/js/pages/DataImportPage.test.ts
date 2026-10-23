import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AmazonInventoryImport } from "@/api/amazonInventoryImports";
import type { BossHistoryApplyResult, BossHistoryPreview } from "@/api/bossInventoryHistories";
import DataImportPage from "@/pages/DataImportPage.vue";
import type { UserRole } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";
import { useUiStore } from "@/stores/ui";
import { formatDateTime } from "@/utils/format";

const { push, fetchAmazonInventoryImports, startAmazonInventoryImport, previewBossInventoryHistory, applyBossInventoryHistory } = vi.hoisted(() => ({
    push: vi.fn(),
    fetchAmazonInventoryImports: vi.fn(),
    startAmazonInventoryImport: vi.fn(),
    previewBossInventoryHistory: vi.fn(),
    applyBossInventoryHistory: vi.fn(),
}));

vi.mock("@/api/amazonInventoryImports", () => ({ fetchAmazonInventoryImports, startAmazonInventoryImport }));
vi.mock("@/api/bossInventoryHistories", () => ({ previewBossInventoryHistory, applyBossInventoryHistory }));

const RouterLinkStub = { props: ["to"], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' };

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
            stubs: { RouterLink: RouterLinkStub, teleport: true },
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

/** 在庫変動履歴の確認の応答（架空）。土曜は既存の調査を更新し、日曜は新しく作る */
function bossPreview(mismatchCount = 1): BossHistoryPreview {
    return {
        periodFrom: "2026-10-16T00:10:00+09:00",
        periodTo: "2026-10-19T10:00:00+09:00",
        rowCount: 701,
        typeCounts: { 在庫調整: 84, API注文引当: 280 },
        baseSurveys: [
            { date: "2026-10-16", surveyId: "31", estimatedReportAt: "2026-10-16T07:37:14+09:00", mismatchCount, unusableReason: null },
            { date: "2026-10-19", surveyId: "34", estimatedReportAt: "2026-10-19T09:13:49+09:00", mismatchCount: 0, unusableReason: null },
        ],
        days: [
            {
                date: "2026-10-17",
                basisAt: "2026-10-17T09:00:00+09:00",
                surveyExists: true,
                surveyId: "32",
                filledSkuCount: 2180,
                nullSkuCount: 4,
                nullReasons: { mismatch: 2, chainBreak: 1, negative: 1 },
            },
            { date: "2026-10-18", basisAt: "2026-10-18T09:00:00+09:00", surveyExists: false, surveyId: null, filledSkuCount: 2182, nullSkuCount: 0, nullReasons: {} },
        ],
        unfillableDates: [{ date: "2026-10-15", reason: "9:00 が在庫変動履歴の期間の外です。" }],
        mismatches: Array.from({ length: Math.min(mismatchCount, 100) }, (_, index) => ({ skuCode: `SKU-${index + 1}`, warehouse: "自社倉庫", reason: "mismatch" as const })),
        mismatchCount,
    };
}

function bossApplyResult(): BossHistoryApplyResult {
    return {
        surveys: [
            { id: "32", executedAt: "2026-10-17T00:05:00Z", importSettingName: null, files: [], productCount: 1, skuCount: 1, allZeroCount: 0, partialZeroCount: 0 },
            { id: "35", executedAt: "2026-10-18T00:00:00Z", importSettingName: null, files: [], productCount: 1, skuCount: 1, allZeroCount: 0, partialZeroCount: 0 },
        ],
        summary: bossPreview(),
    };
}

async function selectBossHistory(wrapper: VueWrapper, file = new File(["処理日時"], "InventoryHistories_FromTo_20261019100500.csv")) {
    const input = wrapper.get('input[aria-label="在庫変動履歴を選択"]');
    Object.defineProperty(input.element, "files", { configurable: true, value: [file] });
    await input.trigger("change");
    await flushPromises();
    return file;
}

describe("DataImportPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { id: "1", loginId: "admin", role: "admin" };
        push.mockReset();
        fetchAmazonInventoryImports.mockReset().mockResolvedValue([]);
        startAmazonInventoryImport.mockReset().mockResolvedValue(amazonImport("queued"));
        previewBossInventoryHistory.mockReset().mockResolvedValue(bossPreview());
        applyBossInventoryHistory.mockReset().mockResolvedValue(bossApplyResult());
    });

    describe("休日のBOSS在庫を補完", () => {
        it("previews the selected history with the days, SKU counts and mismatches", async () => {
            const wrapper = mountPage("editor");
            const file = await selectBossHistory(wrapper);

            expect(previewBossInventoryHistory).toHaveBeenCalledWith(file);
            const preview = wrapper.get('[data-testid="boss-history-preview"]');
            // 日時はブラウザのタイムゾーンで表示する
            expect(preview.text()).toContain(`${formatDateTime("2026-10-16T00:10:00+09:00")} 〜 ${formatDateTime("2026-10-19T10:00:00+09:00")}`);
            expect(preview.text()).toContain("701 行");
            expect(preview.text()).toContain("API注文引当 280・在庫調整 84");
            const bases = preview.findAll("[data-base-survey]");
            expect(bases.map((row) => row.text())).toEqual([
                `10/16（金）${formatDateTime("2026-10-16T07:37:14+09:00").slice(5)}1件`,
                `10/19（月）${formatDateTime("2026-10-19T09:13:49+09:00").slice(5)}0件`,
            ]);
            const days = preview.findAll("[data-fill-day]");
            expect(days[0]!.text()).toContain("10/17（土）既存の調査を更新2,180");
            expect(days[1]!.text()).toContain("10/18（日）新しく作る2,182");
            expect(days[0]!.findAll("[data-null-reason]").map((item) => item.text())).toEqual(["基準の調査と不一致 2件", "履歴のつながりが途切れている 1件", "マイナスの値 1件"]);
            expect(wrapper.get('[data-testid="boss-history-mismatches"]').text()).toContain("基準の調査や履歴と合わないSKUが 1件あります");
            expect(wrapper.get('[data-testid="boss-history-mismatches"]').text()).toContain("SKU-1（自社倉庫）");
            expect(wrapper.get('[data-testid="boss-history-unfillable"]').text()).toContain("10/15（木）: 9:00 が在庫変動履歴の期間の外です。");
            expect(applyBossInventoryHistory).not.toHaveBeenCalled();
        });

        it("lists the first 20 mismatches and counts the rest", async () => {
            previewBossInventoryHistory.mockResolvedValue(bossPreview(130));
            const wrapper = mountPage("editor");
            await selectBossHistory(wrapper);

            const mismatches = wrapper.get('[data-testid="boss-history-mismatches"]');
            expect(mismatches.findAll("li")).toHaveLength(21);
            expect(mismatches.text()).toContain("SKU-20（自社倉庫）");
            expect(mismatches.text()).not.toContain("SKU-21（自社倉庫）");
            expect(mismatches.text()).toContain("ほか 110件");
        });

        it("applies the history, shows a toast with the filled days and reloads the surveys", async () => {
            const wrapper = mountPage("editor");
            const load = vi.spyOn(useSurveysStore(), "load").mockResolvedValue();
            const file = await selectBossHistory(wrapper);

            await wrapper.get('[data-testid="boss-history-apply"]').trigger("click");
            await flushPromises();

            expect(applyBossInventoryHistory).toHaveBeenCalledWith(file);
            expect(useUiStore().toasts.map((toast) => toast.message)).toContain("2日分のBOSS在庫を補完しました");
            expect(load).toHaveBeenCalledTimes(1);
            expect(wrapper.find('[data-testid="boss-history-preview"]').exists()).toBe(false);
            const links = wrapper.get('[data-testid="boss-history-result"]').findAll("a");
            expect(links.map((link) => link.text().replace("chevron_right", "").trim())).toEqual(["10/17（土）", "10/18（日）"]);
            expect(JSON.parse(links[1]!.attributes("data-to")!)).toEqual({ name: "survey-result", params: { id: "35" } });
        });

        it("does not apply when the preview is cancelled", async () => {
            const wrapper = mountPage("editor");
            await selectBossHistory(wrapper);

            await wrapper.get('[data-testid="boss-history-cancel"]').trigger("click");
            await flushPromises();

            expect(applyBossInventoryHistory).not.toHaveBeenCalled();
            expect(wrapper.find('[data-testid="boss-history-preview"]').exists()).toBe(false);
        });

        it("disables the buttons while applying and does not apply twice", async () => {
            let resolveApply: (result: BossHistoryApplyResult) => void = () => undefined;
            applyBossInventoryHistory.mockReturnValue(new Promise((resolve) => (resolveApply = resolve)));
            const wrapper = mountPage("editor");
            vi.spyOn(useSurveysStore(), "load").mockResolvedValue();
            await selectBossHistory(wrapper);

            const applyButton = wrapper.get('[data-testid="boss-history-apply"]');
            await applyButton.trigger("click");
            expect(applyButton.attributes("disabled")).toBeDefined();
            expect(wrapper.get('[data-testid="boss-history-cancel"]').attributes("disabled")).toBeDefined();
            expect(wrapper.get('[data-testid="boss-history-select"]').attributes("disabled")).toBeDefined();
            await applyButton.trigger("click");
            expect(applyBossInventoryHistory).toHaveBeenCalledTimes(1);

            resolveApply(bossApplyResult());
            await flushPromises();
            expect(wrapper.get('[data-testid="boss-history-select"]').attributes("disabled")).toBeUndefined();
        });

        it("shows a 422 validation message in the card without opening the preview", async () => {
            previewBossInventoryHistory.mockRejectedValue({
                isAxiosError: true,
                response: { status: 422, data: { message: "invalid", errors: { file: ["在庫変動履歴の期間に、BOSSのレポートを取り込んだ日が含まれていません。"] } } },
            });
            const wrapper = mountPage("editor");
            await selectBossHistory(wrapper);

            expect(wrapper.find('[data-testid="boss-history-preview"]').exists()).toBe(false);
            expect(wrapper.get('[data-testid="boss-history-error"]').text()).toContain("在庫変動履歴の期間に、BOSSのレポートを取り込んだ日が含まれていません。");
        });

        it("shows a generic message when applying fails for another reason", async () => {
            applyBossInventoryHistory.mockRejectedValue(new Error("network"));
            const wrapper = mountPage("editor");
            await selectBossHistory(wrapper);

            await wrapper.get('[data-testid="boss-history-apply"]').trigger("click");
            await flushPromises();

            expect(wrapper.get('[data-testid="boss-history-error"]').text()).toContain("BOSS在庫を補完できませんでした。時間をおいて、もう一度実行してください。");
            expect(useUiStore().toasts.map((toast) => toast.message)).toContain("BOSS在庫を補完できませんでした");
            expect(wrapper.find('[data-testid="boss-history-result"]').exists()).toBe(false);
        });

        it("is not shown to viewers", async () => {
            const wrapper = mountPage("viewer");
            await flushPromises();

            expect(wrapper.find('[data-testid="boss-history-card"]').exists()).toBe(false);
        });
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

    it("imports files and fetches Amazon stock even when there is no product group", async () => {
        const wrapper = mountPage();
        useImportSettingsStore().settings = [];
        await flushPromises();

        // 取込の対象は CrossWalker で有効な全品番なので、品番グループは無くてもよい
        expect(wrapper.text()).not.toContain("品番グループがありません");
        expect(wrapper.get('[data-testid="amazon-import-start"]').attributes("disabled")).toBeUndefined();
        await selectFolder(wrapper, validImportFiles());
        expect(wrapper.get('[data-testid="run-import"]').attributes("disabled")).toBeUndefined();
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
