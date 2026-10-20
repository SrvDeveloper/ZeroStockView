import { ADMIN_ROLES, ALL_ROLES, EDITOR_ROLES, canAccess, type UserRole } from "@/constants/roles";
import type { IconName } from "@/constants/icons";

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
    icon: IconName;
    /** この節を見られる権限。見られない節は目次・入口ページ・検索・前後の節に出さず、開いても本文を出さない */
    roles: readonly UserRole[];
    /** 使える権限が限られる画面のとき、その権限 */
    audience?: string;
    /** 本文の小見出し。節のコンポーネントの ManualHeading と同じ順・同じ文言にする */
    headings: ManualHeadingMeta[];
    /** この節の要点（節の頭に ✓ 付きで出す。検索の対象にもなる） */
    points: string[];
    /** 検索で拾いたい言葉のうち、節名・小見出し・要点に出てこないもの（画面の用語、言い換え） */
    keywords: string[];
    /** 小見出しのほかに、検索や入口ページから直接開ける場所（注意の囲みなど）。本文の要素の id と合わせる */
    anchors?: ManualHeadingMeta[];
    /** 関連する節の ID */
    related: string[];
    /** この節で説明している画面。開ける権限のユーザーにだけ「画面を開く」ボタンを出す */
    screen?: { routeName: string; label: string; roles: readonly UserRole[] };
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
                roles: ALL_ROLES,
                headings: [
                    { id: "daily-flow", title: "1日の作業の流れ" },
                    { id: "role-permissions", title: "権限ごとにできること" },
                ],
                points: [
                    "在庫調査は1日1件です。品番グループの品番について、4つの倉庫の在庫をSKUごとにまとめて保存します。",
                    "Amazon在庫は毎朝9:00に自動で取得し、ほかの3種類はファイルを取り込みます。",
                    "使える操作は、権限（閲覧者・編集者・特権管理者）で決まります。",
                ],
                keywords: ["概要", "権限", "閲覧者", "編集者", "特権管理者", "CrossWalker", "品番の登録", "1日の流れ"],
                anchors: [{ id: "crosswalker", title: "品番は先にCrossWalkerへ登録してください" }],
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
                roles: EDITOR_ROLES,
                headings: [],
                points: ["BOSSの在庫・商品一覧から、「倉庫毎の在庫数」のCSVを出力します。", "倉庫はすべて選択した状態で実行します。"],
                keywords: ["BOSS", "倉庫毎の在庫数レポート", "自社倉庫", "RFC倉庫", "CSV出力", "ダウンロード"],
                related: ["data-import"],
            },
            {
                id: "basic-operation-ec",
                title: "取込ファイルの準備②：ECストック",
                shortTitle: "② ECストック",
                description: "ECストック在庫の取込に使用するデータは下記の手順で取得します。",
                icon: "touch_app",
                roles: EDITOR_ROLES,
                headings: [],
                points: ["ターコイズのKEEP一覧表で、得意先「59」のCSVを出力します。", "ダウンロードしたファイルは、名前を変えずにそのまま取り込めます。"],
                keywords: ["ターコイズ", "KEEP一覧表", "ECストック", "得意先59", "CSV出力", "ダウンロード"],
                related: ["data-import"],
            },
            {
                id: "basic-operation-free",
                title: "取込ファイルの準備③：フリー在庫",
                shortTitle: "③ フリー在庫",
                description: "フリー在庫の取込に使用するデータは下記の手順で取得します。",
                icon: "touch_app",
                roles: EDITOR_ROLES,
                headings: [],
                points: ["ターコイズの在庫一覧照会で、倉庫「01」のCSVを出力します。", "ゼロ在庫出力のチェックを外してから検索します。"],
                keywords: ["ターコイズ", "在庫一覧照会表", "フリー在庫", "01番倉庫", "ゼロ在庫出力", "ダウンロード"],
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
                roles: EDITOR_ROLES,
                audience: "編集者・特権管理者",
                headings: [
                    { id: "steps", title: "手順" },
                    { id: "same-day", title: "同じ日に追加で取り込むとき" },
                ],
                points: [
                    "CSVは1〜3件です。3種類がそろっていなくても取り込めます。",
                    "種類は中身で自動判定します。ファイル名を変える必要はありません。",
                    "同じ日にもう一度取り込むと、同じ調査が更新されます。",
                ],
                keywords: ["取込", "アップロード", "CSV", "添付", "ファイルを選択", "取込待ち", "追加取込", "エラー", "失敗", "判定できません"],
                anchors: [{ id: "import-errors", title: "取込に失敗したとき" }],
                related: ["basic-operation-amazon", "survey-result", "notes"],
                screen: { routeName: "data-import", label: "データ取込画面を開く", roles: EDITOR_ROLES },
            },
            {
                id: "basic-operation-amazon",
                title: "Amazon在庫の取得",
                shortTitle: "Amazon在庫の取得",
                description: "Amazon在庫（自社発送・FBA）は、AmazonのSP-APIから自動で取得します。ファイルの準備は不要です。",
                icon: "cloud_download",
                roles: ALL_ROLES,
                headings: [
                    { id: "scheduled-fetch", title: "毎朝9:00の自動取得" },
                    { id: "import-status", title: "取得の状態を確認する" },
                    { id: "manual-fetch", title: "手動で取得する（特権管理者のみ）" },
                    { id: "survey-display", title: "在庫調査結果での表示" },
                ],
                points: ["毎朝9:00に自動で取得し、その日の在庫調査に反映します。", "失敗したときは、その日のうちに特権管理者が取り直します。", "ASINのないSKUは、取得の対象外です。"],
                keywords: ["Amazon", "SP-API", "FBA", "自社発送", "自動取得", "手動取得", "取り直し", "取込待ち", "一部未取得", "対象外", "ASIN", "在庫基準日時"],
                related: ["data-import", "survey-result"],
                screen: { routeName: "data-import", label: "データ取込画面を開く", roles: EDITOR_ROLES },
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
                roles: ALL_ROLES,
                headings: [
                    { id: "calendar", title: "カレンダーで見る" },
                    { id: "day-detail", title: "日を選んで詳細を見る" },
                    { id: "list", title: "リストで見る" },
                ],
                points: ["カレンダーとリストの2つの表示で、日ごとの調査を確認します。", "日を選ぶと、取込ファイルのダウンロードやExcel出力ができます。"],
                keywords: ["履歴", "カレンダー", "リスト", "前日比", "一括ダウンロード", "ZIP", "Excel出力", "削除"],
                related: ["survey-result", "file-management"],
                screen: { routeName: "survey-history", label: "在庫調査履歴を開く", roles: ALL_ROLES },
            },
            {
                id: "survey-result",
                title: "在庫調査結果",
                shortTitle: "在庫調査結果",
                description: "1日分の在庫調査を、品番・SKUごとに確認します。",
                icon: "fact_check",
                roles: ALL_ROLES,
                headings: [
                    { id: "summary", title: "画面上部のまとめ" },
                    { id: "stock-table", title: "在庫表の見方" },
                    { id: "filters", title: "絞り込む" },
                    { id: "excel", title: "Excelに出力する" },
                    { id: "memo", title: "メモを書く（特権管理者のみ）" },
                    { id: "refresh", title: "最新の在庫を再読込" },
                    { id: "rerun", title: "保存ファイルで再照合（編集者・特権管理者）" },
                ],
                points: ["「—」は取込待ち、色付きの「0」は在庫0の確定です。", "品番グループや在庫の条件で絞り込み、Excelに出力できます。", "ファイルを添付し直さずに、保存ファイルで再照合できます。"],
                keywords: ["全倉庫ゼロ", "一部の倉庫でゼロ", "在庫ゼロ", "絞込", "Excel出力", "メモ", "再照合", "差異照合メニュー", "内訳", "Ctrl", "取込待ち", "対象外", "総数"],
                related: ["survey-history", "inventory-trend", "notes"],
                screen: { routeName: "survey-history", label: "在庫調査履歴を開く", roles: ALL_ROLES },
            },
            {
                id: "inventory-trend",
                title: "在庫推移",
                shortTitle: "在庫推移",
                description: "品番ごとに、日々の在庫数の移り変わりをグラフで確認します。",
                icon: "show_chart",
                roles: ALL_ROLES,
                headings: [
                    { id: "options", title: "表示する内容を選ぶ" },
                    { id: "product-chart", title: "品番全体のグラフ" },
                    { id: "sku-chart", title: "SKU別のグラフ" },
                    { id: "sku-summary", title: "SKU別サマリー" },
                    { id: "csv-export", title: "日次在庫をCSV出力" },
                ],
                points: ["品番ごとに、倉庫別の在庫の移り変わりをグラフで確認します。", "SKU別サマリーで、期間中の最小・最大と欠品日数を確認します。", "日次在庫はCSVで出力できます。"],
                keywords: ["グラフ", "推移", "欠品", "欠品日数", "日次在庫", "CSV出力", "在庫区分", "モール全体", "期間", "サマリー"],
                related: ["survey-result", "api-settings"],
                screen: { routeName: "inventory-trends", label: "在庫推移を開く", roles: ALL_ROLES },
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
                roles: EDITOR_ROLES,
                audience: "編集者・特権管理者",
                headings: [
                    { id: "list-view", title: "一覧の見方" },
                    { id: "edit", title: "グループを作成・編集する" },
                ],
                points: ["すべてのグループの品番が取込の対象です。", "1グループ50件までです。並び順は、在庫調査結果の表示順になります。"],
                keywords: ["品番", "グループ", "品番の追加", "並び順", "50件", "CrossWalker", "削除"],
                related: ["data-import", "introduction"],
                screen: { routeName: "import-settings", label: "品番グループを開く", roles: EDITOR_ROLES },
            },
            {
                id: "file-management",
                title: "ファイル管理",
                shortTitle: "ファイル管理",
                description: "保存されている取込ファイルを、調査ごとに確認・ダウンロード・削除します。",
                icon: "folder_open",
                roles: ADMIN_ROLES,
                audience: "特権管理者のみ",
                headings: [],
                points: ["取込ファイルは、アップロードから30日間保存されます。", "ファイルを削除しても、調査結果とメモは残ります。"],
                keywords: ["ファイル", "保存期限", "30日", "ダウンロード", "一括削除", "削除"],
                related: ["survey-history", "notes"],
                screen: { routeName: "file-management", label: "ファイル管理を開く", roles: ADMIN_ROLES },
            },
            {
                id: "api-settings",
                title: "API連携設定",
                shortTitle: "API連携設定",
                description: "外部システムが日次在庫データを取得するためのAPIを設定します。",
                icon: "api",
                roles: ADMIN_ROLES,
                audience: "特権管理者のみ",
                headings: [],
                points: ["外部システムが日次在庫を取得するAPIを設定します。", "APIキーは、発行した直後に1回だけ表示されます。"],
                keywords: ["API", "APIキー", "再発行", "IPアドレス", "CIDR", "外部システム", "APIリファレンス"],
                related: ["inventory-trend"],
                screen: { routeName: "api-connection-settings", label: "API連携設定を開く", roles: ADMIN_ROLES },
            },
            {
                id: "user-management",
                title: "ユーザー管理",
                shortTitle: "ユーザー管理",
                description: "ログインできるユーザーと、その権限を管理します。",
                icon: "manage_accounts",
                roles: ADMIN_ROLES,
                audience: "特権管理者のみ",
                headings: [],
                points: ["ユーザーの追加・権限の変更・パスワードの再設定・削除を行います。", "権限を変えると、そのユーザーはすぐにログアウトされます。"],
                keywords: ["ユーザー", "権限", "パスワード", "パスワード変更", "ログアウト", "ログインID", "降格"],
                related: ["introduction"],
                screen: { routeName: "user-management", label: "ユーザー管理を開く", roles: ADMIN_ROLES },
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
                roles: ALL_ROLES,
                headings: [
                    { id: "survey-date", title: "調査の日付とAmazon在庫の反映" },
                    { id: "legacy-surveys", title: "旧方式（5ファイル）の調査" },
                    { id: "integration-status", title: "連携ステータス（編集者・特権管理者）" },
                ],
                points: ["「—」（取込待ち）は、在庫0ではありません。", "取込ファイルの保存期限は30日です。", "Amazon在庫の取り直しは、その日のうちに行います。"],
                keywords: ["注意", "取込待ち", "0との違い", "保存期限", "30日", "旧方式", "5ファイル", "連携ステータス", "調査の日付", "日本時間"],
                anchors: [
                    { id: "pending-vs-zero", title: "取込待ち（—）は在庫0ではありません" },
                    { id: "file-retention", title: "取込ファイルの保存期限は30日です" },
                ],
                related: ["data-import", "basic-operation-amazon"],
            },
        ],
    },
];

export const MANUAL_SECTIONS: ManualSectionMeta[] = MANUAL_CHAPTERS.flatMap((chapter) => chapter.sections);

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

/** その権限で見られる節か */
export function canViewManualSection(section: ManualSectionMeta | string | undefined, role: UserRole | null | undefined): boolean {
    const meta = typeof section === "string" ? findManualSection(section) : section;
    return meta !== undefined && canAccess(role, meta.roles);
}

/** その権限で見られる節だけの章。節がひとつも残らない章は除く */
export function visibleManualChapters(role: UserRole | null | undefined): ManualChapterMeta[] {
    return MANUAL_CHAPTERS.map((chapter) => ({ ...chapter, sections: chapter.sections.filter((section) => canAccess(role, section.roles)) })).filter((chapter) => chapter.sections.length > 0);
}

/** その権限で見られる節の並びで、前後にある節 */
export function adjacentManualSections(id: string, role: UserRole | null | undefined): { previous: ManualSectionMeta | null; next: ManualSectionMeta | null } {
    const sections = MANUAL_SECTIONS.filter((section) => canAccess(role, section.roles));
    const index = sections.findIndex((section) => section.id === id);
    if (index < 0) return { previous: null, next: null };
    return { previous: sections[index - 1] ?? null, next: sections[index + 1] ?? null };
}
