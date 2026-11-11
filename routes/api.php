<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EmailVerificationController;
use App\Http\Controllers\API\RegistrationController;
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

    Route::post('auth/register', [RegistrationController::class, 'store'])->middleware('throttle:api-register');
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:api-verification-resend');
        Route::post('auth/email/verify', [EmailVerificationController::class, 'verify'])
            ->middleware('throttle:api-verification')->name('api.verification.verify');
    });

    Route::prefix('user')->middleware(['auth:sanctum', 'active'])->controller(UsersController::class)->group(function () {
        Route::get('profile', 'profile');
        Route::put('profile', 'updateProfile');
        Route::patch('update-password', 'updatePassword')->middleware('throttle:password-change');
        Route::get('search', 'search')->middleware(['institution.access', 'isAdmin']);
    });
});
