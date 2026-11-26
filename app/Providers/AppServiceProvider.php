<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
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
        $this->configureDefaults();

        Gate::define('manage-advances', fn (User $user): bool => $user->is_admin);
        Gate::define('manage-cash-settings', fn (User $user): bool => $user->is_admin);
        Gate::define('manage-local-backups', fn (User $user): bool => $user->is_admin
            && config('app.desktop') && DB::connection()->getDriverName() === 'sqlite');
        Gate::define('manage-deposits', fn (User $user): bool => $user->is_admin);
        Gate::define('correct-movements', fn (User $user): bool => $user->is_admin);
        Gate::define('manage-movement-sensitivity', fn (User $user): bool => $user->is_admin);
        Gate::define('manage-cash-closings', fn (User $user): bool => $user->is_admin);
        Gate::define('view-monthly-report', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        if (config('app.desktop')) {
            Vite::useHotFile(storage_path('framework/vite.hot'));
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(function (): ?Password {
            if (! app()->isProduction()) {
                return null;
            }

            $rule = Password::min(12)->mixedCase()->letters()->numbers()->symbols();

            return config('app.desktop') ? $rule : $rule->uncompromised();
        });
    }
}
