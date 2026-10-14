<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmazonInventoryImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'status' => $this->status,
            'triggerSource' => $this->trigger_source,
            'source' => $this->source,
            'createdAt' => $this->created_at->toISOString(),
            'startedAt' => $this->started_at?->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(),
            'ownRetrievedAt' => $this->own_retrieved_at?->toISOString(),
            'fbaRetrievedAt' => $this->fba_retrieved_at?->toISOString(),
            'basisAt' => $this->basis_at?->toISOString(),
            'snapshotDate' => $this->snapshot_date?->format('Y-m-d'),
            'targetSkuCount' => $this->target_sku_count,
            'ownRowCount' => $this->own_row_count,
            'fbaRowCount' => $this->fba_row_count,
            'matchedOwnCount' => $this->matched_own_count,
            'matchedFbaCount' => $this->matched_fba_count,
            'errorMessage' => $this->error_message,
            'snapshots' => $this->whenLoaded('snapshots', fn (): array => $this->snapshots->map(fn ($snapshot): array => [
                'productCode' => $snapshot->product_code,
                'skuCode' => $snapshot->sku_code,
                'asin' => $snapshot->asin,
                'amazonOwn' => $snapshot->amazon_own_stock,
                'amazonFba' => $snapshot->amazon_fba_stock,
                'source' => $snapshot->source,
                'basisAt' => $snapshot->basis_at->toISOString(),
            ])->all()),
        ];
    }
}
