<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { fetchInventoryTrendProducts, type InventoryTrendProduct } from "@/api/inventoryTrends";
import AppIcon from "@/components/ui/AppIcon.vue";
import BaseAlert from "@/components/ui/BaseAlert.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import BaseCard from "@/components/ui/BaseCard.vue";
import BaseEmpty from "@/components/ui/BaseEmpty.vue";
import { buildInventoryTrendQuery, parseInventoryTrendQuery } from "@/utils/inventoryTrendQuery";

// 品番一覧の仮の画面（TRD-02）。全体の状況・注目の絞り込み・推移の列などは TRD-03 で作る
const route = useRoute();
const products = ref<InventoryTrendProduct[]>([]);
const loading = ref(true);
const error = ref("");
/** 詳細に引き継ぐ在庫区分と期間 */
const trendQuery = computed(() => buildInventoryTrendQuery(parseInventoryTrendQuery(route.query)));
const sortedProducts = computed(() => [...products.value].sort((left, right) => left.productCode.localeCompare(right.productCode)));

async function loadProducts() {
    loading.value = true;
    error.value = "";
    try {
        products.value = (await fetchInventoryTrendProducts()).data;
    } catch {
        error.value = "品番の一覧を読み込めませんでした。時間をおいて再度お試しください。";
    } finally {
        loading.value = false;
    }
}

onMounted(() => void loadProducts());
</script>

<template>
    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-[22px] leading-tight font-extrabold tracking-[0.04em]">在庫推移</h1>
            <p class="mt-1 text-[12.5px] text-ink-2">品番を選ぶと、その品番の在庫推移を開きます。</p>
        </div>

        <BaseAlert v-if="error" tone="danger" title="品番の一覧を読み込めませんでした">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ error }}</span>
                <BaseButton size="sm" variant="secondary" icon="refresh" @click="() => loadProducts()">再試行</BaseButton>
            </div>
        </BaseAlert>

        <BaseCard title="品番一覧" :padded="false">
            <div v-if="loading" class="flex min-h-40 items-center justify-center gap-2 text-[12.5px] text-muted" role="status">
                <AppIcon name="progress_activity" :size="22" class="animate-spin text-ink-2" />
                品番の一覧を読み込んでいます...
            </div>
            <BaseEmpty v-else-if="!error && sortedProducts.length === 0" icon="show_chart" title="在庫推移のある品番がありません" description="データ取込で調査を作ると、ここに品番が並びます。" />
            <ul v-else data-testid="inventory-trend-product-list" class="divide-y divide-line-2">
                <li v-for="product in sortedProducts" :key="product.productCode" :data-product-code="product.productCode">
                    <RouterLink
                        :to="{ name: 'inventory-trend', params: { productCode: product.productCode }, query: trendQuery }"
                        class="flex items-center justify-between gap-4 px-4 py-2.5 text-left transition-colors hover:bg-surface-2"
                    >
                        <span class="min-w-0">
                            <span class="block font-mono text-[13px] font-semibold text-ink uppercase">{{ product.productCode }}</span>
                            <span class="mt-0.5 block text-[11px] text-muted">{{ product.brand }} ・ {{ product.category }} ・ {{ product.skuCount }} SKU</span>
                        </span>
                        <AppIcon name="chevron_right" :size="18" class="shrink-0 text-ink-2" />
                    </RouterLink>
                </li>
            </ul>
        </BaseCard>
    </div>
</template>
