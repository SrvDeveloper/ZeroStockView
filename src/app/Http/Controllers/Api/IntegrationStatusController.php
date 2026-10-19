<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CrossWalkerConnectionStatus;
use Illuminate\Http\JsonResponse;

class IntegrationStatusController extends Controller
{
    public function show(CrossWalkerConnectionStatus $crossWalkerStatus): JsonResponse
    {
        return response()->json(['data' => ['crossWalker' => $crossWalkerStatus->latest()]]);
    }
}
