<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;

Route::prefix('v1')->controller(AuthController::class)->group(function(){
    Route::post('login', 'login');

    // Route::post('logout', 'logout')->middleware('auth:sanctum');
});
