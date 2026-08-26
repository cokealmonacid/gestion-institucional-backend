<?php

use Illuminate\Support\Facades\Route;
use Modules\Nodes\Http\Controllers\NodesController;

Route::middleware(['auth:sanctum', 'active'])->prefix('v1')->group(function () {
    Route::apiResource('nodes', NodesController::class)
        ->only(['index', 'show'])
        ->middleware('can:institution.view')
        ->names('nodes');
    Route::apiResource('nodes', NodesController::class)
        ->only(['store', 'update', 'destroy'])
        ->middleware('can:nodes.manage')
        ->names('nodes');
});
