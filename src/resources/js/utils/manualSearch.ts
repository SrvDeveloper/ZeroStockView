import { MANUAL_CHAPTERS, type ManualSectionMeta } from "@/constants/manual";

/** 検索結果の 1 件。anchorId があれば、その小見出し・囲みの位置で開く */
export interface ManualSearchResult {
    key: string;
    sectionId: string;
    anchorId: string | null;
    title: string;
    /** 結果の下に出す場所（例: 毎日の作業 › データ取込） */
    path: string;
    /** 一致した要点や説明。節名・小見出しそのものに一致したときは空 */
    snippet: string;
}

interface SearchField {
    text: string;
    weight: number;
    /** 結果に添えて見せる文か（キーワードや節名は見せない） */
    showAsSnippet: boolean;
}

interface SearchEntry {
    result: Omit<ManualSearchResult, "snippet">;
    fields: SearchField[];
    order: number;
}

/** 全角・半角、大文字・小文字、カタカナ・ひらがな、空白の違いを無視して比べる */
export function normalizeForSearch(text: string): string {
    return text
        .normalize("NFKC")
        .toLowerCase()
        .replace(/[ァ-ヶ]/g, (char) => String.fromCharCode(char.charCodeAt(0) - 0x60))
        .replace(/\s+/g, "");
}

function sectionPath(chapterTitle: string, section: ManualSectionMeta): string {
    return chapterTitle ? `${chapterTitle} › ${section.shortTitle}` : section.shortTitle;
}

function buildEntries(): SearchEntry[] {
    const entries: SearchEntry[] = [];
    for (const chapter of MANUAL_CHAPTERS) {
        for (const section of chapter.sections) {
            const path = sectionPath(chapter.title, section);
            entries.push({
                result: { key: section.id, sectionId: section.id, anchorId: null, title: section.title, path: chapter.title || "操作マニュアル" },
                fields: [
                    { text: section.title, weight: 10, showAsSnippet: false },
                    { text: section.shortTitle, weight: 10, showAsSnippet: false },
                    ...section.keywords.map((keyword) => ({ text: keyword, weight: 6, showAsSnippet: false })),
                    ...section.points.map((point) => ({ text: point, weight: 3, showAsSnippet: true })),
                    { text: section.description, weight: 2, showAsSnippet: true },
                    { text: chapter.title, weight: 2, showAsSnippet: false },
                ],
                order: entries.length,
            });
            for (const anchor of [...section.headings, ...(section.anchors ?? [])]) {
                entries.push({
                    result: { key: `${section.id}#${anchor.id}`, sectionId: section.id, anchorId: anchor.id, title: anchor.title, path },
                    fields: [
                        { text: anchor.title, weight: 8, showAsSnippet: false },
                        { text: section.title, weight: 1, showAsSnippet: false },
                    ],
                    order: entries.length,
                });
            }
        }
    }
    return entries.map((entry) => ({ ...entry, fields: entry.fields.filter((field) => field.text !== "") }));
}

const ENTRIES = buildEntries();

/**
 * 節名・小見出し・要点・キーワードから探す。空白で区切った語は、すべてを含むものだけを返す。
 * 一致した場所の重み（節名・小見出し ＞ キーワード ＞ 要点 ＞ 説明）の合計が大きい順、同点ならマニュアルの並び順。
 */
export function searchManual(query: string, limit = 8): ManualSearchResult[] {
    const terms = query
        .normalize("NFKC")
        .split(/\s+/)
        .map(normalizeForSearch)
        .filter((term) => term !== "");
    if (terms.length === 0) return [];

    const scored: { entry: SearchEntry; score: number; snippet: string }[] = [];
    for (const entry of ENTRIES) {
        let score = 0;
        let snippet = "";
        let matchedAll = true;
        for (const term of terms) {
            const matches = entry.fields.filter((field) => normalizeForSearch(field.text).includes(term));
            if (matches.length === 0) {
                matchedAll = false;
                break;
            }
            const best = matches.reduce((top, field) => (field.weight > top.weight ? field : top));
            score += best.weight;
            if (!snippet && best.showAsSnippet) snippet = best.text;
        }
        if (!matchedAll) continue;
        if (normalizeForSearch(entry.result.title).startsWith(terms[0]!)) score += 2;
        scored.push({ entry, score, snippet });
    }

    return scored
        .sort((left, right) => right.score - left.score || left.entry.order - right.entry.order)
        .slice(0, limit)
        .map(({ entry, snippet }) => ({ ...entry.result, snippet }));
}
