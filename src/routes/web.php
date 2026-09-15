<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CrossWalkerController;
use App\Http\Controllers\Api\ImportTargetController;
use App\Http\Controllers\Api\SurveyController;
use App\Http\Controllers\Api\SurveyFileController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('guest');

    Route::middleware('auth')->group(function (): void {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/password', [AuthController::class, 'changePassword']);

        Route::get('/import-targets', [ImportTargetController::class, 'index']);
        Route::put('/import-targets', [ImportTargetController::class, 'store']);
        Route::get('/crosswalker/items', [CrossWalkerController::class, 'index']);

        Route::get('/surveys', [SurveyController::class, 'index']);
        Route::post('/surveys', [SurveyController::class, 'store']);
        Route::get('/surveys/{survey}', [SurveyController::class, 'show']);
        Route::get('/surveys/{survey}/export', [SurveyController::class, 'export']);
        Route::delete('/surveys/{survey}', [SurveyController::class, 'destroy']);
        Route::put('/surveys/{survey}/products/{surveyProduct}/memo', [SurveyController::class, 'updateProductMemo']);
        Route::put('/surveys/{survey}/skus/{surveySku}/memo', [SurveyController::class, 'updateSkuMemo']);

        Route::get('/survey-files/{surveyFile}/download', [SurveyFileController::class, 'download']);
        Route::delete('/survey-files/{surveyFile}', [SurveyFileController::class, 'destroy']);
        Route::get('/surveys/{survey}/files/download', [SurveyFileController::class, 'downloadZip']);
        Route::delete('/surveys/{survey}/files', [SurveyFileController::class, 'destroyForSurvey']);
    });
});

Route::view('/{any}', 'app')->where('any', '.*');
