<?php

namespace App\Providers;

use App\Authorization\InstitutionAuthorization;
use App\Enums\InstitutionAbility;
use App\Http\Responses\ApiResponse;
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
        $onboardingRateLimited = fn (Request $request, array $headers) => ApiResponse::error(
            'AUTH_RATE_LIMITED', 'Too many attempts. Please try again later.', 429,
        )->withHeaders($headers);
        RateLimiter::for('api-register', function (Request $request) use ($onboardingRateLimited) {
            $email = is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : '';

            return [
                Limit::perMinute(config('onboarding.register_per_minute'))->by('ip:'.$request->ip())->response($onboardingRateLimited),
                Limit::perMinute(config('onboarding.register_per_minute'))->by('email:'.hash('sha256', $email))->response($onboardingRateLimited),
            ];
        });
        RateLimiter::for('api-verification-resend', fn (Request $request) => [
            Limit::perSecond(1, config('onboarding.resend_seconds'))->by('interval:'.$request->user()->id)->response($onboardingRateLimited),
            Limit::perHour(config('onboarding.resends_per_hour'))->by('hour:'.$request->user()->id)->response($onboardingRateLimited),
        ]);
        RateLimiter::for('api-verification', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id)->response($onboardingRateLimited));

        $emailRateLimitKey = fn (Request $request): string => is_string($request->input('email'))
            ? Str::lower(trim($request->input('email')))
            : '';
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($emailRateLimitKey($request).'|'.$request->ip()));
        RateLimiter::for('password-recovery', fn (Request $request) => [
            Limit::perMinute(5)->by('ip|'.$request->ip()),
            Limit::perMinute(1)->by('email|'.$emailRateLimitKey($request)),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($emailRateLimitKey($request).'|'.$request->ip()));
        RateLimiter::for('activation', fn (Request $request) => Limit::perMinute(5)->by($emailRateLimitKey($request).'|'.$request->ip()));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by((string) ($request->user()?->id ?? $request->ip())));

        foreach (InstitutionAbility::cases() as $ability) {
            Gate::define(
                $ability->value,
                fn (User $actor): bool => app(InstitutionAuthorization::class)->allows($actor, $ability),
            );
        }
    }
}
