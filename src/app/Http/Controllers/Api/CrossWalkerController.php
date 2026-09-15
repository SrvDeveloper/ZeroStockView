<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchCrossWalkerItemsRequest;
use App\Services\CrossWalkerClient;
use Illuminate\Http\JsonResponse;

class CrossWalkerController extends Controller
{
    public function index(SearchCrossWalkerItemsRequest $request, CrossWalkerClient $crossWalker): JsonResponse
    {
        $data = $request->validated();

        return response()->json($crossWalker->searchItems(
            keyword: $data['keyword'] ?? null,
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 20),
        ));
    }
}
