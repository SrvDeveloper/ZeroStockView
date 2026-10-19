import axios from "axios";

export interface CrossWalkerConnectionStatus {
    status: "succeeded" | "failed";
    checkedAt: string;
    message: string | null;
}

export interface IntegrationStatusResponse {
    /** 一度も接続していなければ null */
    crossWalker: CrossWalkerConnectionStatus | null;
}

export async function fetchIntegrationStatus(): Promise<IntegrationStatusResponse> {
    const { data } = await axios.get<{ data: IntegrationStatusResponse }>("/api/integrations/status");
    return data.data;
}
