import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import UserManagementPage from "@/pages/UserManagementPage.vue";
import { useAuthStore } from "@/stores/auth";
import { useImportSettingsStore } from "@/stores/importSettings";
import { useSurveysStore } from "@/stores/surveys";

const { fetchUsers, createUser, updateUser, deleteUser, replace } = vi.hoisted(() => ({
    fetchUsers: vi.fn(),
    createUser: vi.fn(),
    updateUser: vi.fn(),
    deleteUser: vi.fn(),
    replace: vi.fn(),
}));

vi.mock("@/api/users", () => ({ fetchUsers, createUser, updateUser, deleteUser }));
vi.mock("vue-router", () => ({ useRouter: () => ({ replace }) }));

const admin = { id: "1", loginId: "admin", role: "admin" as const };
const editor = { id: "2", loginId: "staff", role: "editor" as const };

function mountPage() {
    return mount(UserManagementPage, { global: { stubs: { Teleport: true } } });
}

async function openCreate(wrapper: ReturnType<typeof mountPage>) {
    await wrapper
        .findAll("button")
        .find((button) => button.text().includes("ユーザーを追加"))!
        .trigger("click");
}

async function fillForm(wrapper: ReturnType<typeof mountPage>, loginId: string, password: string, confirmation = password) {
    await wrapper.get('input[autocomplete="username"]').setValue(loginId);
    const passwordInputs = wrapper.findAll('input[type="password"]');
    await passwordInputs[0]!.setValue(password);
    await passwordInputs[1]!.setValue(confirmation);
}

async function submitForm(wrapper: ReturnType<typeof mountPage>) {
    await wrapper.get("form").trigger("submit");
    await flushPromises();
}

describe("UserManagementPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useAuthStore().user = { ...admin };
        vi.resetAllMocks();
        fetchUsers.mockResolvedValue([admin, editor]);
        replace.mockResolvedValue(undefined);
    });

    it("shows the login IDs and role labels returned by the admin API", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(fetchUsers).toHaveBeenCalledOnce();
        expect(wrapper.text()).toContain("admin");
        expect(wrapper.text()).toContain("特権管理者");
        expect(wrapper.text()).toContain("staff");
        expect(wrapper.text()).toContain("編集者");
        expect(wrapper.text()).toContain("ユーザー 2人");
        expect(wrapper.get('[data-user-id="1"] [data-current-user]').text()).toBe("自分");
        expect(wrapper.get('[data-user-id="2"] [data-user-role="editor"]').text()).toBe("編集者");
        expect(wrapper.get('[data-user-id="1"] [data-delete-blocked]').attributes("title")).toBe("自分自身・最後の特権管理者は削除できません");
        expect(wrapper.get('[aria-label="adminを削除"]').attributes("disabled")).toBeDefined();
        expect(wrapper.get('[aria-label="staffを削除"]').attributes("disabled")).toBeUndefined();
    });

    it("shows an error when the user list cannot be loaded", async () => {
        fetchUsers.mockRejectedValueOnce(new Error("unavailable"));
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain("ユーザー一覧を取得できませんでした。");
        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("再読み込み"))!
            .trigger("click");
        await flushPromises();
        expect(fetchUsers).toHaveBeenCalledTimes(2);
        expect(wrapper.get('[data-user-id="2"]').text()).toContain("staff");
    });

    it("does not request users when an editor mounts the page directly", async () => {
        useAuthStore().user = { ...editor };
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toBe("");
        expect(fetchUsers).not.toHaveBeenCalled();
    });

    it("creates a user with a confirmed initial password", async () => {
        createUser.mockResolvedValue({ id: "3", loginId: "reader", role: "viewer" });
        const wrapper = mountPage();
        await flushPromises();
        await openCreate(wrapper);
        await fillForm(wrapper, "reader", "initialPass123");
        await submitForm(wrapper);

        expect(createUser).toHaveBeenCalledWith({ loginId: "reader", password: "initialPass123", passwordConfirmation: "initialPass123", role: "viewer" });
        expect(wrapper.get('[data-user-id="3"]').text()).toContain("閲覧者");
        expect(wrapper.text()).not.toContain("initialPass123");
    });

    it("shows local and API validation errors beside the affected fields", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await openCreate(wrapper);
        await submitForm(wrapper);
        expect(wrapper.text()).toContain("初期パスワードを入力してください");
        expect(wrapper.text()).toContain("確認用パスワードを入力してください");
        expect(createUser).not.toHaveBeenCalled();

        await fillForm(wrapper, "existing", "initialPass123", "different12345");
        await submitForm(wrapper);
        expect(wrapper.text()).toContain("確認用パスワードが一致しません");
        expect(createUser).not.toHaveBeenCalled();

        await fillForm(wrapper, "existing", "initialPass123");
        createUser.mockRejectedValueOnce({ isAxiosError: true, response: { status: 422, data: { errors: { login_id: ["The login id has already been taken."] } } } });
        await submitForm(wrapper);
        expect(wrapper.text()).toContain("このログインIDはすでに使用されています");
    });

    it("updates an editor's role with an empty password and explains session expiry", async () => {
        updateUser.mockResolvedValue({ ...editor, role: "viewer" });
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[aria-label="staffを編集"]').trigger("click");
        await wrapper.get('[data-role-option="viewer"] input').setValue(true);

        expect(wrapper.text()).toContain("このユーザーの全セッションが即時終了します");
        await submitForm(wrapper);

        expect(updateUser).toHaveBeenCalledWith("2", { loginId: "staff", password: "", passwordConfirmation: "", role: "viewer" });
        expect(wrapper.get('[data-user-id="2"]').text()).toContain("閲覧者");
    });

    it("resets a password without changing the user's role", async () => {
        updateUser.mockResolvedValue({ ...editor });
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[aria-label="staffを編集"]').trigger("click");
        expect(wrapper.find('input[type="password"]').exists()).toBe(false);
        await wrapper.get('[data-testid="open-password-reset"]').trigger("click");
        await fillForm(wrapper, "staff", "replacement123");
        expect(wrapper.findAll('[data-password-rules] [data-valid="true"]')).toHaveLength(2);
        await submitForm(wrapper);

        expect(updateUser).toHaveBeenCalledWith("2", { loginId: "staff", password: "replacement123", passwordConfirmation: "replacement123", role: "editor" });
        expect(replace).not.toHaveBeenCalled();
    });

    it("explains that the last admin cannot be demoted or deleted", async () => {
        fetchUsers.mockResolvedValueOnce([admin]);
        const wrapper = mountPage();
        await flushPromises();
        expect(wrapper.get('[data-user-id="1"] [data-delete-blocked]').attributes("title")).toContain("最後の特権管理者は削除できません");

        await wrapper.get('[aria-label="adminを編集"]').trigger("click");
        await wrapper.get('[data-role-option="editor"] input').setValue(true);
        expect(wrapper.text()).toContain("最後の特権管理者は降格できません");
        await submitForm(wrapper);
        expect(updateUser).not.toHaveBeenCalled();
    });

    it("deletes a user after confirmation and keeps API errors visible", async () => {
        deleteUser.mockRejectedValueOnce({ isAxiosError: true, response: { status: 422, data: { errors: { role: ["最後の特権管理者は変更・削除できません。"] } } } }).mockResolvedValueOnce(undefined);
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[aria-label="staffを削除"]').trigger("click");
        expect(wrapper.text()).toContain("このユーザーの全セッションは即時終了します");

        await wrapper
            .findAll("button")
            .find((button) => button.text() === "削除する")!
            .trigger("click");
        await flushPromises();
        expect(wrapper.text()).toContain("最後の特権管理者は変更・削除できません");
        expect(wrapper.find('[data-user-id="2"]').exists()).toBe(true);

        await wrapper
            .findAll("button")
            .find((button) => button.text() === "削除する")!
            .trigger("click");
        await flushPromises();
        expect(deleteUser).toHaveBeenCalledWith("2");
        expect(wrapper.find('[data-user-id="2"]').exists()).toBe(false);
    });

    it("describes each role and keeps the password fields closed while editing until requested", async () => {
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[aria-label="staffを編集"]').trigger("click");

        expect(wrapper.findAll("[data-role-option]").map((option) => option.attributes("data-role-option"))).toEqual(["admin", "editor", "viewer"]);
        expect(wrapper.get('[data-role-option="editor"]').text()).toContain("データ取込・再照合・品番グループの追加と編集");
        expect((wrapper.get('[data-role-option="editor"] input').element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.find("[data-password-fields]").exists()).toBe(false);

        await wrapper.get('[data-testid="open-password-reset"]').trigger("click");
        expect(wrapper.find("[data-password-fields]").exists()).toBe(true);
        await submitForm(wrapper);
        expect(wrapper.text()).toContain("新しいパスワードを入力してください");
        expect(updateUser).not.toHaveBeenCalled();

        await wrapper
            .findAll("button")
            .find((button) => button.text() === "再設定をやめる")!
            .trigger("click");
        expect(wrapper.find("[data-password-fields]").exists()).toBe(false);
    });

    it("ends the local session and opens login after changing its own role", async () => {
        fetchUsers.mockResolvedValueOnce([admin, { id: "3", loginId: "other", role: "admin" }]);
        updateUser.mockResolvedValue({ ...admin, role: "editor" });
        useSurveysStore().surveys = [{ id: "survey", executedAt: "2026-10-16T00:00:00Z", importSettingName: null, products: [], files: [] }];
        useImportSettingsStore().settings = [{ id: "setting", name: "test", productCodes: [], products: [], lastSyncedAt: null }];
        const wrapper = mountPage();
        await flushPromises();
        await wrapper.get('[aria-label="adminを編集"]').trigger("click");
        await wrapper.get('[data-role-option="editor"] input').setValue(true);
        expect(wrapper.text()).toContain("自分の権限を変更した場合はログイン画面に戻ります");
        await submitForm(wrapper);

        expect(updateUser).toHaveBeenCalledWith("1", { loginId: "admin", password: "", passwordConfirmation: "", role: "editor" });
        expect(useAuthStore().user).toBeNull();
        expect(useSurveysStore().surveys).toHaveLength(0);
        expect(useImportSettingsStore().settings).toHaveLength(0);
        expect(replace).toHaveBeenCalledWith({ name: "login" });
    });
});
