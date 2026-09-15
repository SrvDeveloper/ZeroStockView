<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SurveyFileController extends Controller
{
    public function download(SurveyFile $surveyFile): StreamedResponse
    {
        abort_unless(Storage::disk($surveyFile->disk)->exists($surveyFile->stored_path), 404);

        return Storage::disk($surveyFile->disk)->download(
            $surveyFile->stored_path,
            $surveyFile->original_name,
        );
    }

    public function destroy(SurveyFile $surveyFile): JsonResponse
    {
        Storage::disk($surveyFile->disk)->delete($surveyFile->stored_path);
        $surveyFile->delete();

        return response()->json(status: 204);
    }

    public function destroyForSurvey(Survey $survey): JsonResponse
    {
        foreach ($survey->files as $file) {
            Storage::disk($file->disk)->delete($file->stored_path);
            $file->delete();
        }

        return response()->json(status: 204);
    }

    public function downloadZip(Survey $survey): BinaryFileResponse
    {
        $files = $survey->files;
        abort_if($files->isEmpty(), 404);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'zsv-');
        if ($temporaryPath === false) {
            throw new RuntimeException('ZIPファイルを作成できませんでした。');
        }

        $zip = new ZipArchive;
        if ($zip->open($temporaryPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIPファイルを作成できませんでした。');
        }

        foreach ($files as $file) {
            if (! Storage::disk($file->disk)->exists($file->stored_path)) {
                continue;
            }
            $contents = Storage::disk($file->disk)->get($file->stored_path);
            $zip->addFromString($file->original_name, $contents);
        }
        $zip->close();

        $fileName = $survey->executed_at->format('YmdHi').'_取込ファイル一式.zip';

        return response()->download($temporaryPath, $fileName)->deleteFileAfterSend();
    }
}
