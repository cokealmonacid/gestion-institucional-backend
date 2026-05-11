<?php

use Illuminate\Support\Facades\Route;
use Modules\Institution\Http\Controllers\API\InstitutionsController;

// Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
//     Route::apiResource('institutions', InstitutionController::class)->names('institution');
// });

Route::prefix('v1')->controller(InstitutionsController::class)->group(function(){
    Route::get('/institutions', 'index');
});