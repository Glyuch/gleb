<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->guardTestDatabase();
        $this->configureDefaults();
    }

    /**
     * Fail fast if a test run is pointed at a non-sqlite database. On the server config is
     * cached for production (MySQL), so a bare `php artisan test` would otherwise boot with
     * the cached prod connection and RefreshDatabase would wipe production data. This runs at
     * boot — before any test's RefreshDatabase — and is inert outside the testing environment.
     */
    protected function guardTestDatabase(): void
    {
        if ($this->app->environment('testing') && config('database.default') !== 'sqlite') {
            throw new \RuntimeException(
                'Tests are pointed at the ['.config('database.default').'] connection, not sqlite — '
                .'this usually means a cached production config. Run `php artisan config:clear` before testing.'
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Relaxed per product feedback (users found the old 12-char + mixed-case + symbol
        // rule too hard). Production now only requires 8 characters; non-prod stays unconstrained.
        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(8)
            : null,
        );
    }
}
