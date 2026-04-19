<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\FileReceiveController;

// Admin if needed (Sanctum)
// Route::middleware('auth:sanctum')->group(function () {
    Route::post('/request-file', [DownloadController::class, 'requestFile']);
    Route::get('/requested-files', [DownloadController::class, 'index']);
    Route::get('/requested-files/{id}', [DownloadController::class, 'show']);
// });

// Client
Route::middleware('client.auth')->group(function () {
    Route::get('/pending', [DownloadController::class, 'pending']);
    Route::post('/files/{download}/upload', [FileReceiveController::class, 'upload'])->name('api.files.upload');
    Route::post('/files/{download}/fail', [DownloadController::class, 'markFailed']);
});