<script setup lang="ts">
import type { CalendarDay } from "@/utils/surveyCalendar";

defineProps<{ days: CalendarDay[]; selectedId: string | null }>();
const emit = defineEmits<{ select: [surveyId: string] }>();

const WEEKDAYS = ["日", "月", "火", "水", "木", "金", "土"];
const LAMP_LABEL = { ok: "Amazon 取込済み", warn: "Amazon 未確定", error: "Amazon 取込失敗" } as const;

function weekdayColor(weekday: number): string {
    if (weekday === 0) return "text-sunday";
    if (weekday === 6) return "text-saturday";
    return "";
}

function dayLabel(day: CalendarDay): string {
    const parts = [`${day.day}日`, `全倉庫ゼロ ${day.allZeroCount} SKU`];
    if (day.increased) parts.push("前の調査日より増加");
    parts.push(`取込ファイル ${day.fileCount}／3`);
    if (day.amazonLamp) parts.push(LAMP_LABEL[day.amazonLamp]);
    return parts.join("、");
}
</script>

<template>
    <div class="grid grid-cols-7 gap-1.5" role="group" aria-label="調査カレンダー">
        <span v-for="(weekday, index) in WEEKDAYS" :key="weekday" class="px-1 text-[10.5px] font-medium text-muted" :class="weekdayColor(index)" aria-hidden="true">{{ weekday }}</span>
        <template v-for="(day, index) in days" :key="day.date || `blank-${index}`">
            <span v-if="day.kind === 'blank'" aria-hidden="true"></span>
            <button
                v-else-if="day.kind === 'survey'"
                type="button"
                :data-day="day.date"
                data-day-kind="survey"
                :aria-pressed="day.survey!.id === selectedId"
                :aria-label="dayLabel(day)"
                class="flex min-h-[76px] flex-col items-start gap-1.5 rounded-chip border bg-surface px-2 py-[7px] text-left shadow-raised transition hover:cursor-pointer hover:border-line-stronger focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ink"
                :class="day.survey!.id === selectedId ? 'border-ink shadow-[0_0_0_1px_var(--color-ink)]' : 'border-line'"
                @click="emit('select', day.survey!.id)"
            >
                <span class="flex items-center justify-between self-stretch">
                    <span class="font-num text-[17px] leading-none font-semibold" :class="weekdayColor(day.weekday)">{{ day.day }}</span>
                    <span
                        v-if="day.amazonLamp"
                        class="h-[7px] w-[7px] rounded-full"
                        :class="{
                            'bg-lamp-ok shadow-[0_0_0_3px_rgb(31_157_85/0.18)]': day.amazonLamp === 'ok',
                            'bg-lamp-warn shadow-[0_0_0_3px_rgb(217_158_0/0.2)]': day.amazonLamp === 'warn',
                            'bg-lamp-error shadow-[0_0_0_3px_rgb(210_58_47/0.18)]': day.amazonLamp === 'error',
                        }"
                        :data-lamp-state="day.amazonLamp"
                        aria-hidden="true"
                    ></span>
                </span>
                <span
                    data-all-zero
                    :data-increased="day.increased ? 'true' : undefined"
                    class="inline-flex items-baseline gap-[3px] rounded-[3px] px-1.5 text-[10px] whitespace-nowrap"
                    :class="day.increased ? 'bg-signal text-ink' : 'bg-tint text-ink-2'"
                    aria-hidden="true"
                    >全ゼロ <b class="font-num text-sm font-bold text-ink">{{ day.allZeroCount }}</b
                    >{{ day.increased ? " ▲" : "" }}</span
                >
                <span class="inline-flex gap-[3px]" aria-hidden="true">
                    <i v-for="slot in 3" :key="slot" class="block h-2.5 w-2.5 rounded-[3px]" :class="slot <= day.fileCount! ? 'bg-ink' : 'shadow-[inset_0_0_0_1.5px_var(--color-line-stronger)]'"></i>
                </span>
            </button>
            <span
                v-else
                :data-day="day.date"
                :data-day-kind="day.kind"
                class="flex min-h-[76px] flex-col gap-1 rounded-chip border px-2 py-[7px]"
                :class="day.kind === 'none' ? 'border-dashed border-line-stronger' : 'border-line-2 bg-white/35'"
            >
                <span class="font-num text-[17px] leading-none font-semibold" :class="day.kind === 'future' ? 'text-faint' : weekdayColor(day.weekday)">{{ day.day }}</span>
                <span v-if="day.kind === 'none'" class="text-[10px] text-muted">調査なし</span>
            </span>
        </template>
    </div>
</template>
