<script setup lang="ts">
import { computed } from "vue";
import ManualSearch from "@/components/manual/ManualSearch.vue";
import AppIcon from "@/components/ui/AppIcon.vue";
import StatusLamp from "@/components/ui/StatusLamp.vue";
import { ROLE_DESCRIPTIONS, ROLE_LABELS } from "@/constants/roles";
import { useAuthStore } from "@/stores/auth";

/** 節（と、あればその中の小見出し・囲み）へのリンク先 */
function manualLink(section: string, anchor = "") {
    return { name: "manual", params: { section }, ...(anchor ? { hash: `#${anchor}` } : {}) };
}

const auth = useAuthStore();
const role = computed(() => auth.role);

const popularLinks = [
    { label: "取込待ち（—）と0の違い", to: manualLink("notes", "pending-vs-zero") },
    { label: "保存ファイルで再照合", to: manualLink("survey-result", "rerun") },
    { label: "Amazon在庫の取り直し", to: manualLink("basic-operation-amazon", "manual-fetch") },
];

/** 1日の流れ。強調するのは、毎日の作業の中心になるデータ取込 */
const dailyFlow = [
    {
        number: "01",
        badge: "自動・毎朝9:00",
        badgeTone: "ok",
        title: "Amazon在庫を取得",
        text: "SP-APIから自動で取得されます。操作は不要です。",
        link: "Amazon在庫の取得",
        to: manualLink("basic-operation-amazon"),
    },
    {
        number: "02",
        badge: "BOSS・ターコイズ",
        badgeTone: "neutral",
        title: "3つのファイルを準備",
        text: "倉庫毎の在庫数レポート、KEEP一覧表、在庫一覧照会表をダウンロードします。",
        link: "取込ファイルの準備",
        to: manualLink("basic-operation-boss"),
    },
    {
        number: "03",
        badge: "編集者以上",
        badgeTone: "neutral",
        title: "データ取込",
        text: "1〜3件のCSVを添付して実行します。その日の在庫調査が作られます。",
        link: "データ取込",
        to: manualLink("data-import"),
        emphasis: true,
    },
    { number: "04", badge: "全員", badgeTone: "neutral", title: "結果を確認", text: "在庫調査履歴から結果を開き、在庫ゼロのSKUを確認します。", link: "在庫調査結果", to: manualLink("survey-result") },
];

interface ManualTask {
    title: string;
    caption: string;
    icon: string;
    /** 注意を促すカードは黄色のアイコンにする */
    tone?: "signal";
    items: { label: string; to: ReturnType<typeof manualLink>; badge?: string }[];
}

/** やりたいことから探す。badge は使える権限が限られる項目だけに付ける */
const tasks: ManualTask[] = [
    {
        title: "取込ファイルを準備する",
        caption: "BOSS・ターコイズでの操作",
        icon: "description",
        items: [
            { label: "① 倉庫毎の在庫数レポート（BOSS）", to: manualLink("basic-operation-boss") },
            { label: "② KEEP一覧表（ECストック）", to: manualLink("basic-operation-ec") },
            { label: "③ 在庫一覧照会表（フリー在庫）", to: manualLink("basic-operation-free") },
        ],
    },
    {
        title: "毎日の作業をする",
        caption: "取込とAmazon在庫",
        icon: "upload",
        items: [
            { label: "データ取込", to: manualLink("data-import"), badge: "編集者以上" },
            { label: "Amazon在庫の取得・取り直し", to: manualLink("basic-operation-amazon") },
            { label: "同じ日に追加で取り込む", to: manualLink("data-import", "same-day") },
        ],
    },
    {
        title: "結果を確認する",
        caption: "履歴・結果・推移・出力",
        icon: "show_chart",
        items: [
            { label: "在庫調査履歴（カレンダー・リスト）", to: manualLink("survey-history") },
            { label: "在庫調査結果の見方・絞込", to: manualLink("survey-result") },
            { label: "在庫推移・日次在庫CSV", to: manualLink("inventory-trend") },
        ],
    },
    {
        title: "設定を変える",
        caption: "品番・ファイル・API・ユーザー",
        icon: "tune",
        items: [
            { label: "品番グループ", to: manualLink("import-settings"), badge: "編集者以上" },
            { label: "ファイル管理", to: manualLink("file-management"), badge: "特権管理者" },
            { label: "API連携設定", to: manualLink("api-settings"), badge: "特権管理者" },
            { label: "ユーザー管理", to: manualLink("user-management"), badge: "特権管理者" },
        ],
    },
    {
        title: "困ったとき・注意事項",
        caption: "間違えやすい点",
        icon: "warning",
        tone: "signal",
        items: [
            { label: "取込に失敗したとき", to: manualLink("data-import", "import-errors") },
            { label: "取込ファイルの保存期限（30日）", to: manualLink("notes", "file-retention") },
            { label: "旧方式（5ファイル）の調査", to: manualLink("notes", "legacy-surveys") },
        ],
    },
    {
        title: "はじめに",
        caption: "システムの全体像",
        icon: "menu_book",
        items: [
            { label: "このシステムでできること", to: manualLink("introduction") },
            { label: "権限ごとにできること", to: manualLink("introduction", "role-permissions") },
            { label: "品番はCrossWalkerへ事前登録", to: manualLink("introduction", "crosswalker") },
        ],
    },
];
</script>

<template>
    <div class="mx-auto flex w-full max-w-[1160px] flex-col gap-9" data-manual-home>
        <!-- 見出し：検索と、ログイン中のユーザーの権限 -->
        <section class="flex flex-wrap items-end gap-8 rounded-panel bg-ink px-6 py-8 text-sidebar-strong sm:px-10 sm:py-9" aria-labelledby="manual-home-title">
            <div class="flex min-w-0 flex-[999_1_460px] flex-col gap-[18px]">
                <div class="flex flex-col gap-2">
                    <p class="text-[12px] font-bold tracking-[0.12em] text-signal">OPERATION MANUAL</p>
                    <h1 id="manual-home-title" class="text-[30px] leading-tight font-extrabold tracking-[0.02em] sm:text-[34px]">操作マニュアル</h1>
                    <p class="text-[15px] leading-[1.7] text-primary-200">毎日の取込から結果の確認まで、やりたいことや画面の名前から探せます。</p>
                </div>
                <ManualSearch size="large" class="max-w-[620px]" />
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[12.5px]">
                    <span class="text-faint">よく見られている：</span>
                    <RouterLink
                        v-for="link in popularLinks"
                        :key="link.label"
                        :to="link.to"
                        class="border-b border-sidebar-strong/40 text-sidebar-strong hover:border-sidebar-strong"
                        data-popular-link
                        >{{ link.label }}</RouterLink
                    >
                </div>
            </div>
            <div v-if="role" class="flex min-w-0 flex-[1_1_260px] flex-col gap-2.5 rounded-card border border-white/10 bg-white/6 px-5 py-[18px]" data-home-role>
                <p class="text-[11.5px] text-faint">あなたの権限</p>
                <p class="text-[20px] font-bold">{{ ROLE_LABELS[role] }}</p>
                <p class="text-[12.5px] leading-[1.7] text-primary-200">{{ ROLE_DESCRIPTIONS[role] }}。使える権限が限られる画面の説明には、その権限の札が付きます。</p>
                <RouterLink :to="manualLink('introduction', 'role-permissions')" class="inline-flex items-center gap-1 text-[12.5px] font-bold text-sidebar-strong hover:underline"
                    >権限ごとにできること<AppIcon name="arrow_forward" :size="15"
                /></RouterLink>
            </div>
        </section>

        <!-- 1日の流れ -->
        <section class="flex flex-col gap-4" aria-labelledby="manual-home-flow">
            <div class="flex flex-wrap items-baseline gap-x-3.5 gap-y-1">
                <h2 id="manual-home-flow" class="text-[22px] font-extrabold">1日の流れ</h2>
                <p class="text-[13px] text-muted">上から順に進めれば、その日の在庫調査ができあがります。</p>
            </div>
            <ol class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" data-home-flow>
                <li
                    v-for="step in dailyFlow"
                    :key="step.number"
                    class="flex flex-col gap-2.5 rounded-panel bg-surface p-[18px] shadow-card"
                    :class="step.emphasis ? 'border-2 border-ink p-[17px]' : 'border border-line'"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-num text-[34px] leading-none font-semibold" aria-hidden="true">{{ step.number }}</span>
                        <span class="rounded-chip px-2 py-0.5 text-[11px] font-bold" :class="step.badgeTone === 'ok' ? 'bg-ok-soft text-ok-ink' : 'bg-tint text-ink-2'">{{ step.badge }}</span>
                    </div>
                    <h3 class="text-[16px] font-bold">{{ step.title }}</h3>
                    <p class="text-[13px] leading-[1.7] text-ink-2">{{ step.text }}</p>
                    <RouterLink :to="step.to" class="mt-auto inline-flex items-center gap-1 text-[12.5px] font-bold text-ink hover:underline"
                        >{{ step.link }}<AppIcon name="arrow_forward" :size="15"
                    /></RouterLink>
                </li>
            </ol>
        </section>

        <!-- やりたいことから探す -->
        <section class="flex flex-col gap-4" aria-labelledby="manual-home-tasks">
            <h2 id="manual-home-tasks" class="text-[22px] font-extrabold">やりたいことから探す</h2>
            <div class="grid grid-cols-1 gap-3.5 md:grid-cols-2 xl:grid-cols-3" data-home-tasks>
                <div v-for="task in tasks" :key="task.title" class="overflow-hidden rounded-panel border border-line bg-surface shadow-card">
                    <div class="flex items-center gap-3 border-b border-line-2 px-[18px] py-4">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-card" :class="task.tone === 'signal' ? 'bg-signal text-ink' : 'bg-ink text-white'">
                            <AppIcon :name="task.icon" :size="19" />
                        </span>
                        <div class="flex min-w-0 flex-col">
                            <h3 class="text-[15.5px] font-bold">{{ task.title }}</h3>
                            <p class="text-[12px] text-muted">{{ task.caption }}</p>
                        </div>
                    </div>
                    <ul class="flex flex-col px-2 pt-1.5 pb-2.5 text-[14px]">
                        <li v-for="item in task.items" :key="item.label">
                            <RouterLink :to="item.to" class="flex min-h-10 items-center justify-between gap-3 rounded-control px-2.5 py-2 text-ink hover:bg-surface-2">
                                <span class="min-w-0">{{ item.label }}</span>
                                <span class="flex shrink-0 items-center gap-2">
                                    <span v-if="item.badge" class="rounded-chip border border-line-strong px-1.5 text-[10.5px] font-bold text-ink-2">{{ item.badge }}</span>
                                    <AppIcon name="chevron_right" :size="18" class="text-faint" />
                                </span>
                            </RouterLink>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- 画面の表示の意味（早見表） -->
        <section class="flex flex-col gap-4" aria-labelledby="manual-home-legend">
            <div class="flex flex-wrap items-baseline gap-x-3.5 gap-y-1">
                <h2 id="manual-home-legend" class="text-[22px] font-extrabold">画面の表示の意味</h2>
                <p class="text-[13px] text-muted">在庫表やランプの表示を、実際の見た目で確認できます。</p>
            </div>
            <div class="grid grid-cols-1 overflow-hidden rounded-panel border border-line bg-surface shadow-card sm:grid-cols-2 xl:grid-cols-4" data-home-legend>
                <div class="flex flex-col gap-2.5 border-b border-line-2 px-5 py-[18px] sm:border-r xl:border-b-0">
                    <span class="font-num text-[26px] leading-none text-muted" aria-hidden="true">—</span>
                    <h3 class="text-[14px] font-bold">取込待ち</h3>
                    <p class="text-[12.5px] leading-[1.7] text-ink-2">まだ数がわからない状態です。在庫0ではありません。</p>
                </div>
                <div class="flex flex-col gap-2.5 border-b border-line-2 px-5 py-[18px] xl:border-r xl:border-b-0">
                    <span class="self-start rounded-chip bg-hazard-pale px-2.5 font-num text-[24px] leading-[1.15] font-semibold shadow-[inset_0_0_0_1px_rgb(185_140_0/0.25)]" aria-hidden="true"
                        >0</span
                    >
                    <h3 class="text-[14px] font-bold">在庫0（確定）</h3>
                    <p class="text-[12.5px] leading-[1.7] text-ink-2">取込済みで、在庫が0と確定しています。</p>
                </div>
                <div class="flex flex-col gap-2.5 border-b border-line-2 px-5 py-[18px] sm:border-r sm:border-b-0">
                    <span class="self-start rounded-chip border border-dashed border-line-stronger px-2 py-0.5 text-[11px] font-bold text-ink-2" aria-hidden="true">取込待ち</span>
                    <h3 class="text-[14px] font-bold">Amazon在庫が未反映</h3>
                    <p class="text-[12.5px] leading-[1.7] text-ink-2">取得に成功すると、自動で反映されます。</p>
                </div>
                <div class="flex flex-col gap-2.5 px-5 py-[18px]">
                    <span class="flex flex-wrap items-center gap-x-3.5 gap-y-1 text-[12px]">
                        <StatusLamp state="ok" label="済み" />
                        <StatusLamp state="warn" label="未確定" />
                        <StatusLamp state="error" label="失敗" />
                    </span>
                    <h3 class="text-[14px] font-bold">ランプの色</h3>
                    <p class="text-[12.5px] leading-[1.7] text-ink-2">Amazon在庫や連携ステータスの状態です。</p>
                </div>
            </div>
            <RouterLink :to="manualLink('survey-result', 'stock-table')" class="inline-flex items-center gap-1 self-start text-[13px] font-bold text-ink hover:underline"
                >在庫表の見方をくわしく見る<AppIcon name="arrow_forward" :size="15"
            /></RouterLink>
        </section>
    </div>
</template>
