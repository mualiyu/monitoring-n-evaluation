<?php

namespace App\Providers;

use App\Policies\MediaPolicy;
use App\Tenancy\CurrentSurface;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        // Media lives in the medialibrary package, so Laravel's policy
        // auto-discovery (App\Models\X → App\Policies\XPolicy) cannot find
        // ours. Without this line `can('view', $media)` answers false for
        // everyone, and — far worse the other way round — a future refactor
        // that moved the check to a Gate would answer TRUE for everyone.
        Gate::policy(Media::class, MediaPolicy::class);

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
