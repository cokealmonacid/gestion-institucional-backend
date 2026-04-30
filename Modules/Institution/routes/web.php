<?php

use Illuminate\Support\Facades\Route;
use Modules\Institution\Http\Controllers\InstitutionController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('institutions', InstitutionController::class)->names('institution');
});
