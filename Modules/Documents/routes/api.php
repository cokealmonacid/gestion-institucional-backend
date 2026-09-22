<?php

use Illuminate\Support\Facades\Route;
use Modules\Documents\Http\Controllers\API\DocumentHistoryController;
use Modules\Documents\Http\Controllers\API\DocumentsController;
use Modules\Documents\Http\Controllers\API\DocumentTagsController;
use Modules\Documents\Http\Controllers\API\DocumentVersionNotesController;
use Modules\Documents\Http\Controllers\API\DocumentVersionsController;

Route::middleware(['auth:sanctum', 'active', 'can:institution.view'])->prefix('v1')->group(function () {
    Route::controller(DocumentsController::class)->group(function () {
        Route::get('/institution/documents', 'index');
        Route::get('/institution/documents/search', 'search');
        Route::get('/institution/tree-directory/{node_id}/documents', 'indexByNode');
        Route::post('/institution/tree-directory/{node_id}/documents', 'store');
        Route::get('/documents/{document_id}', 'show');
        Route::get('/documents/{document_id}/responsible-options', 'responsibleOptions');
        Route::patch('/documents/{document_id}/responsible', 'updateResponsible');
        Route::patch('/documents/{document_id}', 'update')->middleware('can:documents.manage');
        Route::delete('/documents/{document_id}', 'destroy')->middleware('can:documents.manage');
        Route::patch('/documents/{document_id}/activate', 'activate')->middleware('can:documents.manage');
        Route::get('/documents/{document_id}/download', 'download');
    });

    Route::controller(DocumentVersionNotesController::class)->group(function () {
        Route::get('/documents/{document_id}/versions/notes', 'index')
            ->name('document-version-notes.index');
        Route::get('/documents/{document_id}/versions/{version_id}/note/history', 'history')
            ->name('document-version-notes.history');
        Route::patch('/documents/{document_id}/versions/{version_id}/note', 'update')
            ->name('document-version-notes.update');
    });

    Route::get('/documents/{document_id}/history', [DocumentHistoryController::class, 'index'])
        ->middleware('can:traceability.view');

    Route::controller(DocumentVersionsController::class)->group(function () {
        Route::get('/documents/{document_id}/versions', 'index');
        Route::post('/documents/{document_id}/versions', 'store');
        Route::get('/documents/{document_id}/versions/{version_id}', 'show');
        Route::get('/documents/{document_id}/versions/{version_id}/download', 'download');
        Route::delete('/documents/{document_id}/versions/{version_id}', 'destroy')->middleware('can:versions.manage');
        Route::patch('/documents/{document_id}/versions/{version_id}/activate', 'activate')->middleware('can:versions.manage');
        Route::patch('/documents/{document_id}/versions/{version_id}/current', 'current');
    });
});

Route::prefix('v1')->middleware(['auth:sanctum', 'active', 'can:documents.tag'])->controller(DocumentTagsController::class)->group(function () {
    Route::post('institution/document/{document_id}/tags', 'store');
    Route::patch('institution/document/{document_id}/tags', 'update');
    Route::delete('institution/document/{document_id}/tags', 'destroy');
});
