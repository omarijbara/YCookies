<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UsageMeteringWidget extends BaseWidget
{
    protected static ?int $sort = -1; // Put it at the top of the dashboard
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return [];
        }

        // Domain Limits
        $domainLimit = $tenant->domain_limit;
        $domainsUsed = $tenant->domains()->count();
        $domainPercent = $domainLimit > 0 ? min(100, round(($domainsUsed / $domainLimit) * 100)) : 100;

        // Scan Limits
        $scanLimit = $tenant->scan_limit;
        $scansUsed = $tenant->scans_this_month;
        $scanPercent = $scanLimit > 0 ? min(100, round(($scansUsed / $scanLimit) * 100)) : 100;

        // Determine Plan Name based on stripe_price config matching
        $planName = 'Free Plan';
        if ($tenant->subscribed('default')) {
            $price = $tenant->subscription('default')?->stripe_price;
            if ($price === config('pricing.pro_monthly')) {
                $planName = 'Pro Plan';
            } elseif ($price === config('pricing.agency_monthly')) {
                $planName = 'Agency Plan';
            } elseif ($price === config('pricing.enterprise')) {
                $planName = 'Enterprise Plan';
            } else {
                $planName = 'Paid Plan';
            }
        }

        return [
            Stat::make('Current Plan', $planName)
                ->description($planName === 'Free Plan' ? 'Upgrade to unlock features' : 'Active subscription')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color($planName === 'Free Plan' ? 'gray' : 'success'),

            Stat::make('Domains Quota', "{$domainsUsed} / " . ($domainLimit >= 9000 ? 'Unlimited' : $domainLimit))
                ->description(
                    $domainPercent >= 100 ? 'Limit reached' : "{$domainPercent}% of limit reached"
                )
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color($domainPercent >= 100 ? 'danger' : ($domainPercent > 80 ? 'warning' : 'success')),

            Stat::make('Monthly Scans', "{$scansUsed} / " . ($scanLimit >= 9000 ? 'Unlimited' : $scanLimit))
                ->description(
                    $scanPercent >= 100 ? 'Limit reached' : "{$scanPercent}% of limit reached"
                )
                ->descriptionIcon('heroicon-m-finger-print')
                ->color($scanPercent >= 100 ? 'danger' : ($scanPercent > 80 ? 'warning' : 'success')),
        ];
    }
}
