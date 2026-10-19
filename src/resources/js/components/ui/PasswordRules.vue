<script setup lang="ts">
import { computed } from "vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import { passwordRules } from "@/utils/password";

const props = defineProps<{ password: string }>();

const rules = computed(() => passwordRules(props.password));
</script>

<template>
    <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5" data-password-rules>
        <li v-for="rule in rules" :key="rule.label" class="flex items-center gap-1 text-[11px]" :class="rule.valid ? 'text-ok-ink' : 'text-muted'" :data-valid="rule.valid ? 'true' : 'false'">
            <AppIcon :name="rule.valid ? 'check_circle' : 'radio_button_unchecked'" :size="14" />
            {{ rule.label }}
        </li>
    </ul>
</template>
