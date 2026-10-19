import axios from "axios";
import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import UserManagementPage from "@/pages/UserManagementPage.vue";

vi.mock("axios", () => ({ default: { get: vi.fn() } }));

describe("UserManagementPage", () => {
    beforeEach(() => vi.clearAllMocks());

    it("shows the login IDs and role labels returned by the admin API", async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: {
                users: [
                    { id: "1", loginId: "admin", role: "admin" },
                    { id: "2", loginId: "staff", role: "editor" },
                ],
            },
        });
        const wrapper = mount(UserManagementPage);
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith("/api/users");
        expect(wrapper.text()).toContain("admin");
        expect(wrapper.text()).toContain("特権管理者");
        expect(wrapper.text()).toContain("staff");
        expect(wrapper.text()).toContain("編集者");
    });

    it("shows an error when the user list cannot be loaded", async () => {
        vi.mocked(axios.get).mockRejectedValue(new Error("unavailable"));
        const wrapper = mount(UserManagementPage);
        await flushPromises();

        expect(wrapper.text()).toContain("ユーザー一覧を取得できませんでした。");
    });
});
