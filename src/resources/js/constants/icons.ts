/**
 * 画面で使う Material Symbols のアイコン名。アイコン用フォントはこの一覧のアイコンだけを読み込む（全アイコンだと約 3.9 MB になるため）。
 * アイコンを増やすときはここに足す。一覧にない名前は `IconName` の型エラーになる。Google Fonts の指定に合わせてアルファベット順に並べる。
 */
export const ICON_NAMES = [
    "add",
    "api",
    "arrow_forward",
    "attach_file",
    "category",
    "check",
    "check_circle",
    "chevron_left",
    "chevron_right",
    "close",
    "cloud_download",
    "compare_arrows",
    "delete",
    "delete_sweep",
    "description",
    "download",
    "drag_indicator",
    "edit",
    "edit_note",
    "error",
    "event",
    "expand_less",
    "expand_more",
    "fact_check",
    "filter_alt_off",
    "folder_off",
    "folder_open",
    "folder_zip",
    "group",
    "history",
    "image",
    "info",
    "inventory_2",
    "key",
    "lightbulb",
    "lock",
    "lock_open",
    "manage_accounts",
    "menu",
    "menu_book",
    "open_in_new",
    "person_add",
    "play_arrow",
    "progress_activity",
    "radio_button_unchecked",
    "refresh",
    "search",
    "search_off",
    "show_chart",
    "table_view",
    "touch_app",
    "tune",
    "upload",
    "upload_file",
    "warehouse",
    "warning",
    "waving_hand",
    "wrong_location",
] as const;

export type IconName = (typeof ICON_NAMES)[number];

/**
 * アイコン用フォントの CSS。AppIcon が使う軸の値（塗り 0／1、太さ 400、グレード 0、サイズ 24）だけに固定し、ICON_NAMES のアイコンだけを読み込む。
 * display=block は、フォントが届くまでアイコン名の文字を見せないため。
 */
export const ICON_FONT_URL = `https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0..1,0&icon_names=${ICON_NAMES.join(",")}&display=block`;
