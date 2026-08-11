<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\UsersController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('login', 'login');
        Route::post('logout', 'logout')->middleware(['auth:sanctum', 'active']);
        Route::post('forget-password', 'sendResetLink');
        Route::post('reset-password-confirm', 'resetPassword');
    });

    Route::prefix('user')->middleware(['auth:sanctum', 'active'])->controller(UsersController::class)->group(function () {
        Route::get('profile', 'profile');
        Route::put('profile', 'updateProfile');
        Route::patch('update-password', 'updatePassword');
    });
});
