<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\UsersController;

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('login', 'login');       
        Route::post('logout', 'logout')->middleware('auth:sanctum'); 
        Route::post('forget-password', 'sendResetLink');     
        Route::post('reset-password-confirm', 'resetPassword');
    });

    Route::prefix('user')->middleware('auth:sanctum')->controller(UsersController::class)->group(function () {
        Route::get('profile', 'profile');
    });
});