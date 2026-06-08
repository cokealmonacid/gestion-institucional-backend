<?php

use Illuminate\Support\Facades\Route;
use Modules\Documents\Http\Controllers\API\DocumentsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::controller(DocumentsController::class)->group(function () {
        Route::get('/institution/tree-directory/{node_id}/documents', 'indexByNode');
        Route::post('/institution/tree-directory/{node_id}/documents', 'store');
        Route::get('/documents/{document_id}', 'show');
        Route::patch('/documents/{document_id}', 'update');
        Route::delete('/documents/{document_id}', 'destroy');
        Route::patch('/documents/{document_id}/activate', 'activate');
    });
});

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::controller(DocumentsController::class)->group(function () {
        Route::get('/documents/{document_id}/download', 'download');
    });
});