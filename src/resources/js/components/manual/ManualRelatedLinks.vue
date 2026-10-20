<script setup lang="ts">
import { computed } from "vue";
import { findManualSection, type ManualSectionMeta } from "@/constants/manual";

const props = defineProps<{ ids: string[] }>();

const sections = computed(() => props.ids.map((id) => findManualSection(id)).filter((section): section is ManualSectionMeta => section !== undefined));
</script>

<template>
    <div v-if="sections.length > 0" class="flex flex-col gap-1" data-related-links>
        <p class="text-[11px] font-semibold text-muted">関連する節</p>
        <ul class="flex flex-col gap-0.5">
            <li v-for="section in sections" :key="section.id">
                <RouterLink :to="{ name: 'manual', params: { section: section.id } }" class="text-[13px] text-ink-2 underline-offset-2 hover:text-ink hover:underline">{{ section.title }}</RouterLink>
            </li>
        </ul>
    </div>
</template>
