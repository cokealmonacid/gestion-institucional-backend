<?php

use Illuminate\Support\Facades\Route;
use Modules\Nodes\Http\Controllers\NodesController;

Route::middleware(['auth:sanctum', 'active'])->prefix('v1')->group(function () {
    Route::apiResource('nodes', NodesController::class)->names('nodes');
});
