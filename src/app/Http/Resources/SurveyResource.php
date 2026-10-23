<?php

namespace App\Http\Resources;

use App\Models\SurveyProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResource extends JsonResource
{
    /** どの品番グループにも入っていない品番の分類のキー */
    public const UNGROUPED_KEY = 'ungrouped';

    /** @var array<string, string>|null グループ名 => `setting:ID`（調査に保存されている現在のグループ） */
    private ?array $settingKeysByName = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasSavedAmazonInventory = $this->hasSavedAmazonInventory();

        return SurveySummaryResource::sharedFields($this->resource, $hasSavedAmazonInventory, $this->amazonInventoryStatus()) + [
            'groups' => $this->products->flatMap(fn (SurveyProduct $product): array => $this->memberships($product))
                ->unique('key')
                ->sortBy(fn (array $group): int => $group['key'] === self::UNGROUPED_KEY ? 1 : 0)
                ->map(fn (array $group): array => ['key' => $group['key'], 'name' => $group['name']])->values(),
            'products' => $this->products->map(fn ($product): array => [
                'id' => (string) $product->getKey(),
                'productCode' => $product->product_code,
                'brand' => $product->brand,
                'category' => $product->category,
                'parentAsin' => $product->parent_asin ?? '',
                'memo' => $product->memo ?? '',
                'groups' => $this->memberships($product),
                'skus' => $product->skus->map(fn ($sku): array => [
                    'id' => (string) $sku->getKey(),
                    'skuCode' => $sku->sku_code,
                    'size' => $sku->tq_size,
                    'asin' => $sku->child_asin ?? '',
                    // 合計（Amazon・モール全体・総数）は画面側で計算する（utils/stock.ts）。未確定の null をそのまま返す
                    'stock' => [
                        'amazonOwn' => $sku->amazon_own_stock,
                        'amazonFba' => $sku->amazon_fba_stock,
                        'bossOwn' => $sku->boss_own_stock,
                        'bossRfc' => $sku->boss_rfc_stock,
                        'freeStock' => $sku->free_stock,
                        'ecStock' => $sku->ec_stock,
                    ],
                    'memo' => $sku->memo ?? '',
                ])->values(),
            ])->values(),
        ];
    }

    /** @return array<string, string> */
    private function settingKeysByName(): array
    {
        return $this->settingKeysByName ??= $this->products
            ->flatMap(fn (SurveyProduct $product): array => $product->group_memberships ?? [])
            ->filter(fn (array $membership): bool => str_starts_with($membership['key'], 'setting:'))
            ->pluck('key', 'name')
            ->all();
    }

    /** @return array<int, array{key: string, name: string, sortOrder: int}> */
    private function memberships(SurveyProduct $product): array
    {
        if ($product->group_memberships !== null) {
            // 再照合前の重複（`legacy:名前` と同名の `setting:ID`）が保存済みの調査も、1 つのグループとして返す
            $memberships = SurveyProduct::mergeGroupMemberships($product->group_memberships, $this->settingKeysByName());

            // CrossWalker の全品番を対象にしてからは、どの品番グループにも入っていない品番がある
            return $memberships !== [] ? $memberships : [[
                'key' => self::UNGROUPED_KEY,
                'name' => 'グループなし',
                'sortOrder' => $product->sort_order,
            ]];
        }

        return $this->import_setting_name === null ? [] : [[
            'key' => 'legacy:'.$this->import_setting_name,
            'name' => $this->import_setting_name,
            'sortOrder' => $product->sort_order,
        ]];
    }
}
