<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RunSurveyRequest;
use App\Http\Requests\UpdateMemoRequest;
use App\Http\Resources\SurveyResource;
use App\Http\Resources\SurveySummaryResource;
use App\Models\ImportSetting;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Services\InventoryImportService;
use App\Services\SurveyExcelExporter;
use App\Services\SurveySummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SurveyController extends Controller
{
    /** 調査履歴の一覧。SKU別の在庫とメモは含めず、集計値だけを返す（明細は show） */
    public function index(Request $request, SurveySummaryService $summaries): JsonResponse
    {
        $this->pruneExpiredFiles();

        $surveys = Survey::query()
            ->with(['files', 'amazonImport'])
            ->orderByDesc('executed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $summaryBySurvey = $summaries->summarize($surveys);

        return response()->json([
            'data' => $surveys->map(fn (Survey $survey): array => (new SurveySummaryResource($survey, $summaryBySurvey[$survey->getKey()]))->resolve($request))->values(),
        ]);
    }

    public function store(RunSurveyRequest $request, InventoryImportService $importer): SurveyResource
    {
        $files = $request->file('files');

        $settingId = $request->validated('import_setting_id');
        $setting = $settingId === null ? null : ImportSetting::query()->findOrFail($settingId);

        return new SurveyResource($importer->import($files, $setting));
    }

    public function rerun(Request $request, Survey $survey, InventoryImportService $importer): SurveyResource
    {
        $data = $request->validate([
            'import_setting_id' => ['required', 'integer', 'exists:import_settings,id'],
        ]);
        $setting = ImportSetting::query()->findOrFail($data['import_setting_id']);

        return new SurveyResource($importer->reimport($survey, $setting));
    }

    public function show(Survey $survey): SurveyResource
    {
        return new SurveyResource($survey->load(['products.skus', 'files', 'amazonImport']));
    }

    public function export(Request $request, Survey $survey, SurveyExcelExporter $exporter): BinaryFileResponse
    {
        $data = $request->validate([
            'sku_ids' => ['sometimes', 'array'],
            'sku_ids.*' => ['integer'],
        ]);
        $path = $exporter->export($survey, array_key_exists('sku_ids', $data) ? array_map('intval', $data['sku_ids']) : null);
        $fileName = $survey->executed_at->format('YmdHi').'_在庫調査結果.xlsx';

        return response()->download($path, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function destroy(Survey $survey): JsonResponse
    {
        Storage::disk('local')->delete($survey->files()->pluck('stored_path')->all());
        $survey->delete();

        return response()->json(status: 204);
    }

    public function updateProductMemo(UpdateMemoRequest $request, Survey $survey, SurveyProduct $surveyProduct): JsonResponse
    {
        abort_unless($surveyProduct->survey_id === $survey->getKey(), 404);
        $surveyProduct->update(['memo' => $request->validated('memo') ?: null]);

        return response()->json(['memo' => $surveyProduct->memo ?? '']);
    }

    public function updateSkuMemo(UpdateMemoRequest $request, Survey $survey, SurveySku $surveySku): JsonResponse
    {
        $surveySku->loadMissing('product');
        abort_unless($surveySku->product->survey_id === $survey->getKey(), 404);
        $surveySku->update(['memo' => $request->validated('memo') ?: null]);

        return response()->json(['memo' => $surveySku->memo ?? '']);
    }

    private function pruneExpiredFiles(): void
    {
        $files = SurveyFile::query()
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->stored_path);
            $file->delete();
        }
    }
}
