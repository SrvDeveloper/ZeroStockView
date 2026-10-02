import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import ApiConnectionSettingsPage from "@/pages/ApiConnectionSettingsPage.vue";

const axiosMocks = vi.hoisted(() => ({
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    isAxiosError: vi.fn(() => false),
}));

vi.mock("axios", () => ({
    default: axiosMocks,
}));

const currentSetting = {
    enabled: true,
    apiKeyConfigured: true,
    apiKeyMasked: "zsv_live_••••••••••••7890",
    apiKeyIssuedAt: "2026-09-11T11:36:00Z",
    allowedNetworks: [
        { network: "111.109.69.29", memo: "本社固定IP" },
        { network: "127.0.0.1", memo: "ローカル環境" },
    ],
};

function mountPage() {
    return mount(ApiConnectionSettingsPage, {
        global: {
            stubs: {
                Teleport: true,
            },
        },
    });
}

describe("ApiConnectionSettingsPage", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        axiosMocks.get.mockResolvedValue({ data: currentSetting });
        axiosMocks.put.mockImplementation((_url, payload) =>
            Promise.resolve({
                data: {
                    ...currentSetting,
                    enabled: payload.enabled,
                    allowedNetworks: payload.allowed_networks,
                },
            }),
        );
        axiosMocks.post.mockResolvedValue({
            data: {
                ...currentSetting,
                apiKeyMasked: "zsv_live_••••••••••••4321",
                apiKey: "zsv_live_newly_issued_key_4321",
            },
        });
    });

    it("shows the API status, key card, and allowed IP rows like the reference layout", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(axiosMocks.get).toHaveBeenCalledWith("/api/api-connection-settings");
        expect(wrapper.get('[role="switch"]').attributes("aria-checked")).toBe("true");
        expect(wrapper.text()).toContain("ベースURL: /api/v1");
        expect(wrapper.get('[data-testid="api-key-value"]').text()).toBe("zsv_live_••••••••••••7890");
        expect(wrapper.text()).toContain("111.109.69.29");
        expect(wrapper.text()).toContain("本社固定IP");
        expect(wrapper.text()).toContain("127.0.0.1");
        expect(wrapper.text()).toContain("APIリファレンス");
    });

    it("adds an IP with a memo and saves the complete settings", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('input[placeholder^="203.0.113.10"]').setValue("198.51.100.0/24");
        await wrapper.get('input[placeholder="本社固定IP"]').setValue("物流倉庫");
        await wrapper.get('[data-testid="add-network"]').trigger("click");
        await wrapper.get('[data-testid="save-api-settings"]').trigger("click");
        await flushPromises();

        expect(axiosMocks.put).toHaveBeenCalledWith("/api/api-connection-settings", {
            enabled: true,
            allowed_networks: [
                { network: "111.109.69.29", memo: "本社固定IP" },
                { network: "127.0.0.1", memo: "ローカル環境" },
                { network: "198.51.100.0/24", memo: "物流倉庫" },
            ],
        });
        expect(wrapper.text()).toContain("198.51.100.0/24");
        expect(wrapper.text()).toContain("物流倉庫");
    });

    it("shows a newly rotated key only in the rotate response", async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.get('[data-testid="rotate-api-key"]').trigger("click");
        await flushPromises();

        expect(axiosMocks.post).toHaveBeenCalledWith("/api/api-connection-settings/rotate-key");
        expect(wrapper.get('[data-testid="api-key-value"]').text()).toBe("zsv_live_newly_issued_key_4321");
        expect(wrapper.text()).toContain("このキーを今すぐ安全な場所へ控えてください");
    });

    it("opens and closes the API reference modal", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.find('[data-testid="api-reference-content"]').exists()).toBe(false);

        await wrapper.get('[data-testid="open-api-reference"]').trigger("click");

        const reference = wrapper.get('[data-testid="api-reference-content"]');
        expect(reference.text()).toContain("GET /api/v1/inventory/daily");
        expect(reference.text()).toContain("X-API-Key: 発行したAPIキー");
        expect(reference.text()).toContain("product_code");
        expect(reference.text()).toContain("日本時間・最大365日間");

        await wrapper.get('button[aria-label="閉じる"]').trigger("click");

        expect(wrapper.find('[data-testid="api-reference-content"]').exists()).toBe(false);
    });
});
