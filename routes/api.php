<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\UsersController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('login', 'login')->middleware('throttle:login');
        Route::post('logout', 'logout')->middleware(['auth:sanctum', 'active']);
        Route::post('password-recovery', 'requestPasswordRecovery')->middleware('throttle:password-recovery');
        Route::post('password-reset', 'resetPassword')->middleware('throttle:password-reset');
        Route::post('activation', 'activate')->middleware('throttle:activation');
    });

    Route::prefix('user')->middleware(['auth:sanctum', 'active'])->controller(UsersController::class)->group(function () {
        Route::get('profile', 'profile');
        Route::put('profile', 'updateProfile');
        Route::patch('update-password', 'updatePassword')->middleware('throttle:password-change');
        Route::get('search', 'search')->middleware('isAdmin');
    });
});
