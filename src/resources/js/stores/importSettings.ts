import axios from "axios";
import { defineStore } from "pinia";
import type { ImportSetting, Product } from "@/types";

export const MAX_TARGET_PRODUCTS = 50;

export const useImportSettingsStore = defineStore("importSettings", {
    state: () => ({
        settings: [] as ImportSetting[],
        selectedSettingId: null as string | null,
        loaded: false,
    }),
    getters: {
        selectedSetting: (state): ImportSetting | null => state.settings.find((setting) => setting.id === state.selectedSettingId) ?? null,
        selectedProductCodes: (state): string[] => state.settings.find((setting) => setting.id === state.selectedSettingId)?.productCodes ?? [],
        products: (state): Product[] => state.settings.find((setting) => setting.id === state.selectedSettingId)?.products ?? [],
        lastSyncedAt: (state): string | null => state.settings.find((setting) => setting.id === state.selectedSettingId)?.lastSyncedAt ?? null,
        isConfigured: (state): boolean => (state.settings.find((setting) => setting.id === state.selectedSettingId)?.productCodes.length ?? 0) > 0,
        count: (state): number => state.settings.find((setting) => setting.id === state.selectedSettingId)?.productCodes.length ?? 0,
    },
    actions: {
        select(settingId: string | null) {
            this.selectedSettingId = settingId;
        },
        findProduct(productCode: string) {
            return this.products.find((product) => product.productCode === productCode);
        },
        async load() {
            const { data } = await axios.get<{ settings: ImportSetting[] }>("/api/import-settings");
            this.settings = data.settings;
            if (!this.settings.some((setting) => setting.id === this.selectedSettingId)) {
                this.selectedSettingId = this.settings[0]?.id ?? null;
            }
            this.loaded = true;
        },
        async create(name: string, productCodes: string[]): Promise<ImportSetting> {
            const { data } = await axios.post<ImportSetting>("/api/import-settings", {
                name,
                product_codes: productCodes,
            });
            this.settings.push(data);
            this.selectedSettingId = data.id;
            this.loaded = true;
            return data;
        },
        async update(settingId: string, name: string, productCodes: string[]): Promise<ImportSetting> {
            const { data } = await axios.put<ImportSetting>(`/api/import-settings/${settingId}`, {
                name,
                product_codes: productCodes,
            });
            const index = this.settings.findIndex((setting) => setting.id === settingId);
            if (index >= 0) this.settings[index] = data;
            this.selectedSettingId = data.id;
            return data;
        },
        async remove(settingId: string) {
            await axios.delete(`/api/import-settings/${settingId}`);
            this.settings = this.settings.filter((setting) => setting.id !== settingId);
            if (this.selectedSettingId === settingId) {
                this.selectedSettingId = this.settings[0]?.id ?? null;
            }
        },
    },
});
