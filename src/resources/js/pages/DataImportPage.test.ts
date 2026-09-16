import { mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import FileDropzone from "@/components/ui/FileDropzone.vue";
import DataImportPage from "@/pages/DataImportPage.vue";
import { useImportSettingsStore } from "@/stores/importSettings";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => {
    return { useRouter: () => ({ push }) };
});

function mountPage() {
    setActivePinia(createPinia());
    const settings = useImportSettingsStore();
    settings.selectedProductCodes = ["A-1001"];

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

describe("DataImportPage", () => {
    beforeEach(() => {
        push.mockReset();
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
