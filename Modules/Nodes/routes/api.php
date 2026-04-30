<?php

use Illuminate\Support\Facades\Route;
use Modules\Nodos\Http\Controllers\NodosController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('nodes', NodosController::class)->names('nodes');
});
