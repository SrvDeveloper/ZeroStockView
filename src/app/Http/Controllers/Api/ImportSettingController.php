<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveImportSettingRequest;
use App\Http\Resources\ProductResource;
use App\Models\ImportSetting;
use App\Models\Product;
use App\Services\CrossWalkerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = ImportSetting::query()
            ->with('targets.product.skus')
            ->orderBy('id')
            ->get();

        return response()->json([
            'settings' => $settings->map(fn (ImportSetting $setting): array => $this->serialize($setting)),
        ]);
    }

    public function store(SaveImportSettingRequest $request, CrossWalkerClient $crossWalker): JsonResponse
    {
        $setting = new ImportSetting;
        $this->save($setting, $request->validated(), $crossWalker);

        return response()->json($this->serialize($setting->load('targets.product.skus')), 201);
    }

    public function update(SaveImportSettingRequest $request, ImportSetting $importSetting, CrossWalkerClient $crossWalker): JsonResponse
    {
        $this->save($importSetting, $request->validated(), $crossWalker);

        return response()->json($this->serialize($importSetting->load('targets.product.skus')));
    }

    public function destroy(ImportSetting $importSetting): JsonResponse
    {
        $importSetting->delete();

        return response()->json(status: 204);
    }

    /** @param array{name: string, product_codes: array<int, string>} $data */
    private function save(ImportSetting $setting, array $data, CrossWalkerClient $crossWalker): void
    {
        $items = collect($crossWalker->lookupItems($data['product_codes']))->keyBy('item_no');
        $missingCodes = collect($data['product_codes'])->diff($items->keys());

        if ($missingCodes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'product_codes' => ['CrossWalkerに存在しない品番が含まれています: '.$missingCodes->implode(', ')],
            ]);
        }

        $inactiveCodes = collect($data['product_codes'])
            ->filter(fn (string $productCode): bool => $items->get($productCode)['status'] !== 'active');
        if ($inactiveCodes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'product_codes' => ['無効な品番は取込対象に設定できません: '.$inactiveCodes->implode(', ')],
            ]);
        }

        DB::transaction(function () use ($setting, $data, $items, $crossWalker): void {
            $setting->fill(['name' => $data['name']])->save();
            $setting->targets()->delete();

            foreach ($data['product_codes'] as $productIndex => $productCode) {
                $product = Product::query()->firstOrNew(['product_code' => $productCode]);
                $crossWalker->syncProduct($product, $items->get($productCode));

                $setting->targets()->create([
                    'product_id' => $product->getKey(),
                    'sort_order' => $productIndex + 1,
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    private function serialize(ImportSetting $setting): array
    {
        $targets = $setting->targets;

        return [
            'id' => (string) $setting->getKey(),
            'name' => $setting->name,
            'productCodes' => $targets->pluck('product.product_code')->values(),
            'products' => ProductResource::collection($targets->pluck('product'))->resolve(),
            'lastSyncedAt' => $targets->max(fn ($target) => $target->product->synced_at)?->toISOString(),
        ];
    }
}
