<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AmazonInventoryImportException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AmazonInventoryImportResource;
use App\Jobs\ProcessAmazonInventoryImport;
use App\Models\AmazonInventoryImport;
use App\Services\AmazonInventoryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class AmazonInventoryImportController extends Controller
{
    public function index(AmazonInventoryImportService $service): AnonymousResourceCollection
    {
        $service->expireStale();

        return AmazonInventoryImportResource::collection(
            AmazonInventoryImport::query()->orderByDesc('id')->limit(50)->get(),
        );
    }

    public function store(AmazonInventoryImportService $service): JsonResponse
    {
        try {
            $import = DB::transaction(function () use ($service): AmazonInventoryImport {
                $import = $service->queue('manual');
                Bus::dispatch(new ProcessAmazonInventoryImport($import->id));

                return $import;
            });
        } catch (AmazonInventoryImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new AmazonInventoryImportResource($import))->response()->setStatusCode(202);
    }

    public function show(AmazonInventoryImport $amazonInventoryImport, AmazonInventoryImportService $service): AmazonInventoryImportResource
    {
        $service->expireStale();

        return new AmazonInventoryImportResource($amazonInventoryImport->refresh()->load('snapshots'));
    }
}
