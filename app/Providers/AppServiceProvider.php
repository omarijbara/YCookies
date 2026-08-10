<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope is a dev-only dependency — only register when the package is available
        if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Apply database-stored display settings (timezone, date/time formats)
        try {
            \App\Models\AppSetting::instance()->apply();
        } catch (\Exception $e) {
            // Table may not exist during initial migration
        }

        // All models define $fillable — rely on that instead of global unguard().
        // Also prevent silent data loss from typos in fill() / create() calls.
        Model::preventSilentlyDiscardingAttributes(!app()->isProduction());
        \Laravel\Cashier\Cashier::useCustomerModel(\App\Models\Group::class);

        \App\Models\Domain::observe(\App\Observers\DomainObserver::class);
        \App\Models\CookieGroup::observe(\App\Observers\ConsentVersionObserver::class);
        \App\Models\Service::observe(\App\Observers\ConsentVersionObserver::class);

        // Runtime manifest: recompile on policy-relevant model changes
        // Covers the observer gaps where CookieBar/Blocker changes didn't trigger invalidation
        \App\Models\CookieGroup::observe(\App\Observers\RuntimeModelObserver::class);
        \App\Models\Service::observe(\App\Observers\RuntimeModelObserver::class);
        \App\Models\ScriptBlocker::observe(\App\Observers\RuntimeModelObserver::class);
        \App\Models\ContentBlocker::observe(\App\Observers\RuntimeModelObserver::class);
        \App\Models\CookieBar::observe(\App\Observers\RuntimeModelObserver::class);

        // Scanner job concurrency: max 2 scan jobs can run simultaneously.
        // This prevents the scanner queue from consuming all server resources
        // and starving the proxy/web traffic (which caused the duftz.de outage).
        \Illuminate\Support\Facades\RateLimiter::for('scanner', function ($job) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(2)->by('scanner-global');
        });

        \Illuminate\Support\Facades\RateLimiter::for('api-tenant', function (\Illuminate\Http\Request $request) {
            $siteId = $request->route('site_id')
                ?? $request->input('site_id')
                ?? $request->query('site_id')
                ?? $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(200)->by((string) $siteId);
        });

        \BezhanSalleh\LanguageSwitch\LanguageSwitch::configureUsing(function (\BezhanSalleh\LanguageSwitch\LanguageSwitch $switch) {
            $switch->locales(function () {
                try {
                    return \App\Models\Language::where('is_active', true)->pluck('code')->toArray() ?: ['en'];
                } catch (\Exception $e) {
                    return ['en'];
                }
            })->labels(function () {
                try {
                    return \App\Models\Language::where('is_active', true)->pluck('name', 'code')->toArray() ?: ['en' => 'English'];
                } catch (\Exception $e) {
                    return ['en' => 'English'];
                }
            })->visible(outsidePanels: true);
        });

        // Pulse — restrict dashboard access to super admins
        \Illuminate\Support\Facades\Gate::define('viewPulse', function (\App\Models\User $user) {
            return $user->hasRole('super_admin');
        });

        // Trigger subscription limit enforcement natively on Stripe events
        \Illuminate\Support\Facades\Event::listen(
            \Laravel\Cashier\Events\WebhookReceived::class,
            function ($event) {
                $payload = $event->payload;
                if (in_array($payload['type'] ?? '', ['invoice.payment_failed', 'customer.subscription.deleted'])) {
                    \Illuminate\Support\Facades\Artisan::queue('ycookies:enforce-limits');
                }
            }
        );
    }
}
