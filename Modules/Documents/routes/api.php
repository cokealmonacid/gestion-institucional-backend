<?php

use Illuminate\Support\Facades\Route;
use Modules\Documents\Http\Controllers\DocumentsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('documents', DocumentsController::class)->names('documents');
});
