<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Hourly (heavy) — withoutOverlapping prevents pile-up if previous run is still going ──
Schedule::command('ycookies:run-scans')->hourly()->withoutOverlapping(55)->runInBackground();
Schedule::command('ycookies:ssh-auto-cleanup')->everyFifteenMinutes()->withoutOverlapping(10)->runInBackground();

// ── Every 15 minutes (medium) ──
Schedule::command('ycookies:run-health-checks')->everyFifteenMinutes()->withoutOverlapping(14)->runInBackground();

// ── Every 5 minutes (lightweight) ──
Schedule::command('ycookies:verify-proxy-dns')->everyFiveMinutes()->runInBackground();
Schedule::command('crash-reporter:retry')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('pulse:check')->everyFiveMinutes()->withoutOverlapping();

// ── Every 10 minutes ──
Schedule::job(new \App\Jobs\SyncGlitchTipIssues())->everyTenMinutes()->withoutOverlapping();

// ── Every 6 hours ──
Schedule::command('telemetry:send')->everySixHours()->runInBackground();

// ── Daily tasks — STAGGERED to avoid pile-up ──
// Backups at 01:00 UTC (quietest hour)
Schedule::command('backup:run --only-db')->dailyAt('01:00')->runInBackground();
Schedule::command('backup:clean')->dailyAt('01:30')->runInBackground();

// Traffic digest at 02:00 UTC
Schedule::command('traffic:digest')->dailyAt('02:00')->withoutOverlapping()->runInBackground();

// IAB TCF — GVL refresh at 03:00 UTC
Schedule::command('tcf:update-gvl')->dailyAt('03:00');

// Component Updates at 04:00 UTC
Schedule::command('ycookies:check-component-updates')->dailyAt('04:00');

// GTM scripts at 05:00 UTC
Schedule::command('ycookies:update-local-gtm-scripts')->dailyAt('05:00');

// Subscription limits at 06:00 UTC (was 00:00 — caused pile-up with hourly scans)
Schedule::command('ycookies:enforce-limits')->dailyAt('06:00')->withoutOverlapping();

// GDPR consent purge at 06:30 UTC
Schedule::command('ycookies:purge-consent-logs')->dailyAt('06:30')->withoutOverlapping();

// Legacy token cleanup at 07:00 UTC
Schedule::command('domains:clear-expired-legacy-tokens')->dailyAt('07:00');
