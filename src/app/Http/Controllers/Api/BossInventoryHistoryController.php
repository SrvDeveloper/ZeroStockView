<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportBossInventoryHistoryRequest;
use App\Http\Resources\SurveySummaryResource;
use App\Models\Survey;
use App\Services\BossInventoryHistoryImportService;
use App\Services\SurveySummaryService;
use Illuminate\Http\JsonResponse;

/** BOSS の在庫変動履歴で、休日の BOSS 在庫を補完する（docs/BOSS在庫の休日補完 BHR-04） */
class BossInventoryHistoryController extends Controller
{
    /** どの日をどう補完するかを返す。データベースには書き込まない */
    public function preview(ImportBossInventoryHistoryRequest $request, BossInventoryHistoryImportService $importer): JsonResponse
    {
        return response()->json(['data' => $importer->preview($request->file('file'))]);
    }

    /** 保存する時点の調査でもう一度計算し、補完した調査と確認の内容を返す */
    public function store(ImportBossInventoryHistoryRequest $request, BossInventoryHistoryImportService $importer, SurveySummaryService $summaries): JsonResponse
    {
        $result = $importer->apply($request->file('file'), $request->user());
        $summaryBySurvey = $summaries->summarize($result['surveys']);

        return response()->json([
            'data' => [
                'surveys' => $result['surveys']->map(fn (Survey $survey): array => (new SurveySummaryResource($survey, $summaryBySurvey[$survey->getKey()]))->resolve($request))->values(),
                'summary' => $result['summary'],
            ],
        ], 201);
    }
}
