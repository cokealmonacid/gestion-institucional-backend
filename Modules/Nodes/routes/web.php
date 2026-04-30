<?php

use Illuminate\Support\Facades\Route;
use Modules\Nodos\Http\Controllers\NodosController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('nodes', NodosController::class)->names('nodes');
});
