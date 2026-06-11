<?php

use Illuminate\Support\Facades\Route;
use Modules\Documents\Http\Controllers\API\DocumentsController;
use Modules\Documents\Http\Controllers\API\DocumentVersionsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::controller(DocumentsController::class)->group(function () {
        Route::get('/institution/tree-directory/{node_id}/documents', 'indexByNode');
        Route::post('/institution/tree-directory/{node_id}/documents', 'store');
        Route::get('/documents/{document_id}', 'show');
        Route::patch('/documents/{document_id}', 'update');
        Route::delete('/documents/{document_id}', 'destroy');
        Route::patch('/documents/{document_id}/activate', 'activate');
    });

    Route::controller(DocumentVersionsController::class)->group(function () {
        Route::get('/documents/{document_id}/versions', 'index');
        Route::post('/documents/{document_id}/versions', 'store');
        Route::get('/documents/{document_id}/versions/{version_id}', 'show');
        Route::delete('/documents/{document_id}/versions/{version_id}', 'destroy');
        Route::patch('/documents/{document_id}/versions/{version_id}/activate', 'activate');
        Route::patch('/documents/{document_id}/versions/{version_id}/current', 'current');
    });
});
