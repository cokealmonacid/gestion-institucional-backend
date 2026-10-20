<?php

use App\Platform\Http\Controllers\AccessRecoveryController;
use App\Platform\Http\Controllers\AdministrationController;
use App\Platform\Http\Controllers\AuthenticationController;
use App\Platform\Http\Controllers\InstitutionalUserController;
use App\Platform\Http\Controllers\OnboardingController;
use App\Platform\Http\Controllers\OperationalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['platform.auth', 'platform.can:platform.user_access'])->group(function () {
    Route::get('institutions/{id}/users/{userId}/role-impact', [InstitutionalUserController::class, 'handle'])->defaults('action', 'impact');
    foreach (['role', 'access'] as $action) {
        Route::post('institutions/{id}/users/{userId}/'.$action, [InstitutionalUserController::class, 'handle'])->defaults('action', $action);
    }
});

Route::post('auth/access-recovery-verification', [AccessRecoveryController::class, 'verify'])->middleware('throttle:platform-link');
Route::middleware(['platform.auth', 'platform.can:platform.access_recovery'])->controller(AccessRecoveryController::class)->group(function () {
    Route::get('access-recoveries', 'index');
    Route::post('access-recoveries', 'store');
    Route::get('access-recoveries/{id}', 'show');
    foreach (['complete', 'cancel', 'resend'] as $action) {
        Route::post('access-recoveries/{id}/'.$action, 'transition')->defaults('action', $action);
    }
});

Route::prefix('auth')->controller(AuthenticationController::class)->group(function () {
    Route::post('login', 'login')->middleware('throttle:platform-login');
    Route::post('activation', 'activation')->middleware('throttle:platform-link');
    Route::post('password-recovery', 'recovery')->middleware('throttle:platform-recovery');
    Route::post('password-reset', 'reset')->middleware('throttle:platform-link');
    Route::post('mfa/enrollment', 'enrollment')->middleware('throttle:platform-mfa');
    Route::post('mfa/confirmation', 'confirmation')->middleware('throttle:platform-mfa');
    Route::post('mfa/challenge', 'challenge')->middleware('throttle:platform-mfa');
    Route::middleware('platform.auth')->group(function () {
        Route::get('profile', 'profile');
        Route::post('logout', 'logout');
        Route::patch('password', 'password')->middleware('throttle:platform-mfa');
        Route::post('mfa/recovery-codes', 'codes')->middleware('throttle:platform-mfa');
    });
});
Route::middleware('platform.auth')->controller(AdministrationController::class)->group(function () {
    Route::middleware('platform.can:platform.plans')->group(function () {
        Route::get('plans', 'plans');
        Route::post('plans', 'createPlan');
        Route::get('plans/{id}', 'plan');
        Route::patch('plans/{id}', 'updatePlan');
    });
    Route::middleware('platform.can:platform.metadata')->group(function () {
        Route::get('institutions', 'institutions');
        Route::get('institutions/{id}', 'institution');
        Route::patch('institutions/{id}', 'metadata');
    });
    Route::middleware('platform.can:platform.contracts')->group(function () {
        Route::get('institutions/{id}/contract', 'contract');
        Route::get('institutions/{id}/contract/revisions', 'revisions');
        Route::post('institutions/{id}/contract/revisions', 'revise');
    });
    Route::get('audit', 'audit')->middleware('platform.can:platform.audit');
    Route::get('institutions/{id}/audit', 'audit')->middleware('platform.can:platform.audit');
});

Route::middleware('platform.auth')->controller(OperationalController::class)->group(function () {
    Route::get('institutions/{id}/quotas', 'quotas')->middleware('platform.can:platform.contracts');
    Route::middleware('platform.can:platform.operations')->group(function () {
        Route::post('institutions/{id}/suspension', 'suspension');
        Route::post('institutions/{id}/reactivation', 'reactivation');
    });
    Route::middleware('platform.can:platform.administrator_access')->group(function () {
        Route::get('users', 'users');
        Route::get('institutions/{id}/administrators', 'administrators');
        Route::get('institutions/{id}/administrators/{userId}/invitation', 'administrator');
        Route::post('institutions/{id}/administrators/{userId}/invitation', [OperationalController::class, 'resend']);
        Route::post('institutions/{id}/administrators/{userId}/reactivation', 'reactivateAdministrator');
    });
});

Route::middleware('platform.auth')->controller(OnboardingController::class)->group(function () {
    Route::post('institutions', 'store')->middleware(['platform.can:platform.metadata', 'platform.can:platform.contracts']);
    Route::post('institutions/{id}/administrator-invitations', 'invite')->middleware('platform.can:platform.administrator_access');
    Route::get('operations/{key}', 'operation')->middleware('platform.can:platform.operations');
});
