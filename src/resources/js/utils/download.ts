import axios from "axios";

/** API から受け取ったファイルを、指定したファイル名でブラウザに保存させる */
export async function downloadFromApi(url: string, filename: string): Promise<void> {
    const { data } = await axios.get<Blob>(url, { responseType: "blob" });
    const objectUrl = URL.createObjectURL(data);
    try {
        const link = document.createElement("a");
        link.href = objectUrl;
        link.download = filename;
        link.click();
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
}
