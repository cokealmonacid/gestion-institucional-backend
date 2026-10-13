<?php

namespace App\Providers;

use App\Authorization\InstitutionAuthorization;
use App\Enums\InstitutionAbility;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('password-recovery', fn (Request $request) => [
            Limit::perMinute(5)->by('ip|'.$request->ip()),
            Limit::perMinute(1)->by('email|'.Str::lower((string) $request->input('email'))),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('activation', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by((string) ($request->user()?->id ?? $request->ip())));

        foreach (InstitutionAbility::cases() as $ability) {
            Gate::define(
                $ability->value,
                fn (User $actor): bool => app(InstitutionAuthorization::class)->allows($actor, $ability),
            );
        }
    }
}
