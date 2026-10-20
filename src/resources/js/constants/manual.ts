/** 操作マニュアルの章・節。目次、前後の節、上部バーの現在地、検索で共有する（節の ID は URL になるので変えない） */
export interface ManualHeadingMeta {
    /** 小見出しの ID。ページ内リンクの行き先になる */
    id: string;
    title: string;
}

export interface ManualSectionMeta {
    id: string;
    /** 節の見出し */
    title: string;
    /** 目次に出す短い名前 */
    shortTitle: string;
    description: string;
    icon: string;
    /** 使える権限が限られる画面のとき、その権限 */
    audience?: string;
    /** 本文の小見出し。節のコンポーネントの ManualHeading と同じ順・同じ文言にする */
    headings: ManualHeadingMeta[];
    /** 関連する節の ID */
    related: string[];
}

export interface ManualChapterMeta {
    /** 章の名前。空なら章の見出しを出さず、節だけを並べる */
    title: string;
    sections: ManualSectionMeta[];
}

export const MANUAL_CHAPTERS: ManualChapterMeta[] = [
    {
        title: "",
        sections: [
            {
                id: "introduction",
                title: "はじめに",
                shortTitle: "はじめに",
                description: "このシステムでできることと、1日の作業の流れを説明します。",
                icon: "waving_hand",
                headings: [
                    { id: "daily-flow", title: "1日の作業の流れ" },
                    { id: "role-permissions", title: "権限ごとにできること" },
                ],
                related: ["data-import", "notes"],
            },
        ],
    },
    {
        title: "取込ファイルの準備",
        sections: [
            {
                id: "basic-operation-boss",
                title: "取込ファイルの準備①：BOSS",
                shortTitle: "① BOSS",
                description: "BOSSの取込に使用するデータは下記の手順で取得します。",
                icon: "touch_app",
                headings: [],
                related: ["data-import"],
            },
            {
                id: "basic-operation-ec",
                title: "取込ファイルの準備②：ECストック",
                shortTitle: "② ECストック",
                description: "ECストック在庫の取込に使用するデータは下記の手順で取得します。",
                icon: "touch_app",
                headings: [],
                related: ["data-import"],
            },
            {
                id: "basic-operation-free",
                title: "取込ファイルの準備③：フリー在庫",
                shortTitle: "③ フリー在庫",
                description: "フリー在庫の取込に使用するデータは下記の手順で取得します。",
                icon: "touch_app",
                headings: [],
                related: ["data-import"],
            },
        ],
    },
    {
        title: "毎日の作業",
        sections: [
            {
                id: "data-import",
                title: "データ取込",
                shortTitle: "データ取込",
                description: "準備したファイルを取り込んで、その日の在庫調査を作成・更新します。",
                icon: "upload",
                audience: "編集者・特権管理者",
                headings: [{ id: "same-day", title: "同じ日に追加で取り込むとき" }],
                related: ["basic-operation-amazon", "survey-result", "notes"],
            },
            {
                id: "basic-operation-amazon",
                title: "Amazon在庫の取得",
                shortTitle: "Amazon在庫の取得",
                description: "Amazon在庫（自社発送・FBA）は、AmazonのSP-APIから自動で取得します。ファイルの準備は不要です。",
                icon: "cloud_download",
                headings: [
                    { id: "scheduled-fetch", title: "毎朝9:00の自動取得" },
                    { id: "import-status", title: "取得の状態を確認する" },
                    { id: "manual-fetch", title: "手動で取得する（特権管理者のみ）" },
                    { id: "survey-display", title: "在庫調査結果での表示" },
                ],
                related: ["data-import", "survey-result"],
            },
        ],
    },
    {
        title: "結果を確認する",
        sections: [
            {
                id: "survey-history",
                title: "在庫調査履歴",
                shortTitle: "在庫調査履歴",
                description: "これまでの在庫調査を、日付ごとに確認します。",
                icon: "history",
                headings: [
                    { id: "calendar", title: "カレンダーで見る" },
                    { id: "day-detail", title: "日を選んで詳細を見る" },
                    { id: "list", title: "リストで見る" },
                ],
                related: ["survey-result", "file-management"],
            },
            {
                id: "survey-result",
                title: "在庫調査結果",
                shortTitle: "在庫調査結果",
                description: "1日分の在庫調査を、品番・SKUごとに確認します。",
                icon: "fact_check",
                headings: [
                    { id: "summary", title: "画面上部のまとめ" },
                    { id: "stock-table", title: "在庫表の見方" },
                    { id: "filters", title: "絞り込む" },
                    { id: "excel", title: "Excelに出力する" },
                    { id: "memo", title: "メモを書く（特権管理者のみ）" },
                    { id: "refresh", title: "最新の在庫を再読込" },
                    { id: "rerun", title: "保存ファイルで再照合（編集者・特権管理者）" },
                ],
                related: ["survey-history", "inventory-trend", "notes"],
            },
            {
                id: "inventory-trend",
                title: "在庫推移",
                shortTitle: "在庫推移",
                description: "品番ごとに、日々の在庫数の移り変わりをグラフで確認します。",
                icon: "show_chart",
                headings: [
                    { id: "options", title: "表示する内容を選ぶ" },
                    { id: "product-chart", title: "品番全体のグラフ" },
                    { id: "sku-chart", title: "SKU別のグラフ" },
                    { id: "sku-summary", title: "SKU別サマリー" },
                    { id: "csv-export", title: "日次在庫をCSV出力" },
                ],
                related: ["survey-result", "api-settings"],
            },
        ],
    },
    {
        title: "設定",
        sections: [
            {
                id: "import-settings",
                title: "品番グループ",
                shortTitle: "品番グループ",
                description: "取込の対象にする品番を、用途ごとのグループにまとめます。",
                icon: "category",
                audience: "編集者・特権管理者",
                headings: [{ id: "list-view", title: "一覧の見方" }],
                related: ["data-import", "introduction"],
            },
            {
                id: "file-management",
                title: "ファイル管理",
                shortTitle: "ファイル管理",
                description: "保存されている取込ファイルを、調査ごとに確認・ダウンロード・削除します。",
                icon: "folder_open",
                audience: "特権管理者のみ",
                headings: [],
                related: ["survey-history", "notes"],
            },
            {
                id: "api-settings",
                title: "API連携設定",
                shortTitle: "API連携設定",
                description: "外部システムが日次在庫データを取得するためのAPIを設定します。",
                icon: "api",
                audience: "特権管理者のみ",
                headings: [],
                related: ["inventory-trend"],
            },
            {
                id: "user-management",
                title: "ユーザー管理",
                shortTitle: "ユーザー管理",
                description: "ログインできるユーザーと、その権限を管理します。",
                icon: "manage_accounts",
                audience: "特権管理者のみ",
                headings: [],
                related: ["introduction"],
            },
        ],
    },
    {
        title: "",
        sections: [
            {
                id: "notes",
                title: "注意事項",
                shortTitle: "注意事項",
                description: "操作時に間違えやすい点をまとめます。",
                icon: "error",
                headings: [
                    { id: "survey-date", title: "調査の日付とAmazon在庫の反映" },
                    { id: "legacy-surveys", title: "旧方式（5ファイル）の調査" },
                    { id: "integration-status", title: "連携ステータス（編集者・特権管理者）" },
                ],
                related: ["data-import", "basic-operation-amazon"],
            },
        ],
    },
];

export const MANUAL_SECTIONS: ManualSectionMeta[] = MANUAL_CHAPTERS.flatMap((chapter) => chapter.sections);

/** `/manual` で最初に開く節（入口ページができるまで） */
export const DEFAULT_MANUAL_SECTION_ID = "introduction";

export function findManualSection(id: string | undefined): ManualSectionMeta | undefined {
    return MANUAL_SECTIONS.find((section) => section.id === id);
}

export function isManualSection(id: string | undefined): boolean {
    return findManualSection(id) !== undefined;
}

/** 節が属する章の名前。章の名前がない節は空 */
export function manualChapterOf(id: string): string {
    return MANUAL_CHAPTERS.find((chapter) => chapter.sections.some((section) => section.id === id))?.title ?? "";
}

/** 目次の並びで前後にある節 */
export function adjacentManualSections(id: string): { previous: ManualSectionMeta | null; next: ManualSectionMeta | null } {
    const index = MANUAL_SECTIONS.findIndex((section) => section.id === id);
    if (index < 0) return { previous: null, next: null };
    return { previous: MANUAL_SECTIONS[index - 1] ?? null, next: MANUAL_SECTIONS[index + 1] ?? null };
}
