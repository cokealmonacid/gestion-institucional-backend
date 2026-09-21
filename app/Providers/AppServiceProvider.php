<?php

namespace App\Providers;

use App\Authorization\InstitutionAuthorization;
use App\Enums\InstitutionAbility;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        foreach (InstitutionAbility::cases() as $ability) {
            Gate::define(
                $ability->value,
                fn (User $actor): bool => app(InstitutionAuthorization::class)->allows($actor, $ability),
            );
        }
    }
}
