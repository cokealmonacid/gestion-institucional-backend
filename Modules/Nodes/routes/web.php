<?php

use Illuminate\Support\Facades\Route;
use Modules\Nodes\Http\Controllers\NodesController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('nodes', NodesController::class)->names('nodes');
});
