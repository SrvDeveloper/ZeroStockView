<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveImportTargetsRequest;
use App\Http\Resources\ProductResource;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Services\CrossWalkerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportTargetController extends Controller
{
    public function index(): JsonResponse
    {
        $targets = ImportTarget::query()
            ->with('product.skus')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'productCodes' => $targets->pluck('product.product_code')->values(),
            'products' => ProductResource::collection($targets->pluck('product'))->resolve(),
            'lastSyncedAt' => $targets->max(fn (ImportTarget $target) => $target->product->synced_at)?->toISOString(),
        ]);
    }

    public function store(SaveImportTargetsRequest $request, CrossWalkerClient $crossWalker): JsonResponse
    {
        $data = $request->validated();
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

        DB::transaction(function () use ($data, $items, $crossWalker): void {
            ImportTarget::query()->delete();

            foreach ($data['product_codes'] as $productIndex => $productCode) {
                $product = Product::query()->firstOrNew(['product_code' => $productCode]);
                $crossWalker->syncProduct($product, $items->get($productCode));

                ImportTarget::query()->create([
                    'product_id' => $product->getKey(),
                    'sort_order' => $productIndex + 1,
                ]);
            }
        });

        return $this->index();
    }
}
