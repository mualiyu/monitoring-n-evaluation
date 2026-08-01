<?php

namespace App\Providers;

use App\Tenancy\CurrentSurface;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);

        // Must be scoped, not resolved fresh per call: ResolveSurface writes
        // the surface here and the Fortify response contracts read it later in
        // the same request. Without the binding every app() call returns a new
        // instance, so every login response falls back to Surface::Portal.
        $this->app->scoped(CurrentSurface::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Date::use(CarbonImmutable::class);

        Password::defaults(function () {
            $rule = Password::min((int) config('platform.auth.password_min_length'))
                ->letters()->mixedCase()->numbers()->symbols();

            return config('platform.auth.check_compromised_passwords') && ! app()->runningUnitTests()
                ? $rule->uncompromised()
                : $rule;
        });
    }
}
