export function formatDateTime(iso: string): string {
    const d = new Date(iso);
    const y = d.getFullYear();
    const m = `${d.getMonth() + 1}`.padStart(2, "0");
    const day = `${d.getDate()}`.padStart(2, "0");
    const hh = `${d.getHours()}`.padStart(2, "0");
    const mm = `${d.getMinutes()}`.padStart(2, "0");
    return `${y}/${m}/${day} ${hh}:${mm}`;
}

export function formatDate(iso: string): string {
    const d = new Date(iso);
    const y = d.getFullYear();
    const m = `${d.getMonth() + 1}`.padStart(2, "0");
    const day = `${d.getDate()}`.padStart(2, "0");
    return `${y}/${m}/${day}`;
}

/** 例: 10/17 9:00（月・日・時はゼロで埋めない） */
export function formatShortDateTime(iso: string): string {
    const d = new Date(iso);
    const mm = `${d.getMinutes()}`.padStart(2, "0");
    return `${d.getMonth() + 1}/${d.getDate()} ${d.getHours()}:${mm}`;
}

export function formatFileTimestamp(iso: string): string {
    const d = new Date(iso);
    const y = d.getFullYear();
    const m = `${d.getMonth() + 1}`.padStart(2, "0");
    const day = `${d.getDate()}`.padStart(2, "0");
    const hh = `${d.getHours()}`.padStart(2, "0");
    const mm = `${d.getMinutes()}`.padStart(2, "0");
    return `${y}${m}${day}${hh}${mm}`;
}

export function daysSince(iso: string, now: Date = new Date()): number {
    const then = new Date(iso).getTime();
    const diffMs = now.getTime() - then;
    return Math.floor(diffMs / (1000 * 60 * 60 * 24));
}

export function formatNumber(n: number): string {
    return n.toLocaleString("ja-JP");
}

export function formatSizeKb(kb: number): string {
    if (kb >= 1024) {
        return `${(kb / 1024).toFixed(1)} MB`;
    }
    return `${kb} KB`;
}
