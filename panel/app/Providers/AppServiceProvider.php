<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        // TLS ends at a proxy in front of the container, so links follow the configured address.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Every write route and API call goes through this gate, not only the UI.
        Gate::define('manage-servers', fn (User $user): bool => $user->isAdmin());
        Gate::define('manage-users', fn (User $user): bool => $user->isAdmin());
        // Admins, and whoever an admin ticked for it on the Users page.
        Gate::define('manage-launcher', fn (User $user): bool => $user->isAdmin() || $user->manages_launcher);
        // The modpack search belongs to the configuration form, so whoever may configure a server may use it.
        Gate::define('search-modpacks', fn (User $user): bool => $user->isAdmin()
            || $user->servers()->whereJsonContains('server_user.permissions', 'configure')->exists());

        // Per token, so two apps of one user do not eat each other's budget; an app polling
        // server state would spend 60 a minute on polling alone.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->bearerToken() !== null ? hash('sha256', $request->bearerToken()) : (string) ($request->user()?->id ?: $request->ip()),
        ));

        // Unauthenticated, so by address. Starting a pairing writes a row, so it is tight; the
        // app then polls every 3 seconds, which is 20 a minute, and gets room above that.
        RateLimiter::for('pairing', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('pairing-poll', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
        // The launcher's public downloads, shared by its three addresses.
        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
