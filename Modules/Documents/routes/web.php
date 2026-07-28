<?php

use Illuminate\Support\Facades\Route;
use Modules\Documents\Http\Controllers\DocumentsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('documents', DocumentsController::class)->names('documents');
});
