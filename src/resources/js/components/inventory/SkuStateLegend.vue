<template>
    <div class="flex flex-wrap gap-3 text-[11px] text-ink-2" aria-label="SKUの状況の凡例">
        <span class="inline-flex items-center gap-1.5"><span class="block size-[9px] rounded-[2px] bg-[#7d8893]" aria-hidden="true"></span>在庫あり</span>
        <span class="inline-flex items-center gap-1.5"
            ><span class="block size-[9px] rounded-[2px] bg-signal shadow-[inset_0_0_0_1px_var(--color-signal-border)]" aria-hidden="true"></span>欠品（0）</span
        >
        <span class="inline-flex items-center gap-1.5"
            ><span
                class="block size-[9px] rounded-[2px] bg-[repeating-linear-gradient(45deg,rgb(17_22_26/0.35)_0_1.5px,transparent_1.5px_4px)] shadow-[inset_0_0_0_1px_var(--color-line-stronger)]"
                aria-hidden="true"
            ></span
            >未確定（—）</span
        >
    </div>
</template>
