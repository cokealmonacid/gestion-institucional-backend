<?php

use Illuminate\Support\Facades\Route;
use Modules\Institution\Http\Controllers\API\InstitutionsController;
use Modules\Institution\Http\Controllers\API\TagsController;
use Modules\Institution\Http\Controllers\API\TreeDirectoryController;
use Modules\Institution\Http\Controllers\API\UsersController;

// Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
//     Route::apiResource('institutions', InstitutionController::class)->names('institution');
// });

Route::prefix('v1')->controller(InstitutionsController::class)->group(function () {
    Route::get('/institutions', 'index');
});

Route::prefix('v1')->middleware(['auth:sanctum', 'active', 'can:institution.view'])->controller(TreeDirectoryController::class)->group(function () {
    Route::get('/institution/tree-directory', 'index');
    Route::get('/institution/tree-directory/{node_id}/children', 'children');
    Route::get('/institution/tree-directory/{node_id}', 'show');
    Route::post('/institution/tree-directory', 'storeCanonical');
    Route::post('/institution/tree-directory/{node_id}', 'store');
    Route::delete('/institution/tree-directory/{node_id}', 'destroy')->middleware('can:nodes.manage');
    Route::patch('/institution/tree-directory/{node_id}/activate', 'activate')->middleware('can:nodes.manage');
});

Route::prefix('v1')->middleware(['auth:sanctum', 'active', 'can:institution.view'])->controller(TagsController::class)->group(function () {
    Route::get('/institution/tag', 'index');
});

Route::prefix('v1')->middleware(['auth:sanctum', 'active', 'can:tags.manage'])->controller(TagsController::class)->group(function () {
    Route::post('/institution/tag', 'store');
    Route::delete('/institution/tag/{tag_id}', 'destroy');
});

Route::prefix('v1')->middleware(['auth:sanctum', 'active', 'can:users.manage'])->controller(UsersController::class)->group(function () {
    Route::get('/institution/users', 'index');
});

Route::prefix('v1')->middleware(['auth:sanctum', 'active', 'can:users.manage'])->controller(UsersController::class)->group(function () {
    Route::post('/institution/users', 'store');
    Route::patch('/institution/users', 'update');
    Route::delete('/institution/users', 'destroy');
});
