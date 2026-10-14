import axios from "axios";

export type AmazonImportStatus = "queued" | "running" | "succeeded" | "failed";

export interface AmazonInventoryImport {
    id: string;
    status: AmazonImportStatus;
    triggerSource: "manual" | "scheduled";
    source: "sp_api";
    createdAt: string;
    startedAt: string | null;
    completedAt: string | null;
    basisAt: string | null;
    targetSkuCount: number | null;
    ownRowCount: number | null;
    fbaRowCount: number | null;
    matchedOwnCount: number | null;
    matchedFbaCount: number | null;
    errorMessage: string | null;
}

export async function fetchAmazonInventoryImports(): Promise<AmazonInventoryImport[]> {
    const { data } = await axios.get<{ data: AmazonInventoryImport[] }>("/api/amazon-inventory-imports");
    return data.data;
}

export async function startAmazonInventoryImport(): Promise<AmazonInventoryImport> {
    const { data } = await axios.post<{ data: AmazonInventoryImport }>("/api/amazon-inventory-imports");
    return data.data;
}
