<?php

namespace App\Platform\Providers;

use App\Platform\Authorization\PlatformPermission;
use App\Platform\Models\PlatformAuthChallenge;
use App\Platform\Models\PlatformOperator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (PlatformPermission::cases() as $permission) {
            Gate::define($permission->value, fn ($actor) => $actor instanceof PlatformOperator && $actor->active && $actor->verified_at && $actor->mfa_confirmed_at);
        }
        RateLimiter::for('platform-login', fn (Request $r) => [Limit::perMinute(5)->by('platform-login|'.$this->email($r).'|'.$r->ip()), Limit::perMinute(20)->by('platform-login-ip|'.$r->ip())]);
        RateLimiter::for('platform-mfa', function (Request $r) {
            $id = $r->input('challenge_id');
            $challenge = is_string($id) && Str::isUuid($id) ? PlatformAuthChallenge::find($id) : null;
            $operator = $r->user() instanceof PlatformOperator ? $r->user()->id : ($challenge?->operator_id ?? 'unknown');

            return [Limit::perMinute(5)->by('platform-mfa|'.$operator.'|'.$r->ip()), Limit::perMinute(20)->by('platform-mfa-ip|'.$r->ip())];
        });
        RateLimiter::for('platform-recovery', fn (Request $r) => [Limit::perMinute(1)->by('platform-recovery|'.$this->email($r)), Limit::perMinute(5)->by('platform-recovery-ip|'.$r->ip())]);
        RateLimiter::for('platform-link', fn (Request $r) => Limit::perMinute(5)->by('platform-link|'.$this->email($r).'|'.$r->ip()));
        Route::middleware('api')->prefix('api/v1/platform')->group(base_path('routes/platform.php'));
    }

    private function email(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? strtolower($email) : 'invalid';
    }
}
