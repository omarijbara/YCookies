<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;

class BillingUpgrade extends Page
{
    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-credit-card';
    }

    protected string $view = 'filament.pages.billing-upgrade';

    protected static ?int $navigationSort = 99;

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        $tenant = Filament::getTenant();

        return ($tenant && $tenant->subscribed('default'))
            ? __('ycookies.resources.subscription')
            : __('ycookies.resources.billing_upgrade');
    }

    public function getHeading(): string
    {
        $tenant = Filament::getTenant();

        return ($tenant && $tenant->subscribed('default'))
            ? __('ycookies.resources.manage_subscription')
            : __('ycookies.resources.upgrade_required');
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.billing_upgrade');
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant) {
            $tenant->load('subscriptions');
        }

        return [
            'group' => $tenant,
        ];
    }

    public function manageSubscription()
    {
        $tenant = Filament::getTenant();

        if (! $tenant || ! $tenant->subscribed('default')) {
            return;
        }

        try {
            $url = $tenant->billingPortalUrl(
                self::getUrl(['tenant' => $tenant])
            );

            return $this->redirect($url);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            \Filament\Notifications\Notification::make()
                ->title('Stripe not configured')
                ->body('The Stripe API key is not set.')
                ->danger()
                ->send();
        }
    }

    public function upgradeMonthly()
    {
        $tenant = Filament::getTenant();

        if (! $tenant || $tenant->subscribed('default')) {
            return;
        }

        try {
            return $tenant->newSubscription('default', 'price_1T9PudCqOt3Mipp1bJUzv0EC')
                ->checkout([
                    'success_url' => \App\Filament\Pages\Dashboard::getUrl(['tenant' => $tenant]),
                    'cancel_url' => self::getUrl(['tenant' => $tenant]),
                ]);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            \Filament\Notifications\Notification::make()
                ->title('Stripe not configured')
                ->body('The Stripe API key is not set. Please configure STRIPE_KEY and STRIPE_SECRET in your .env file.')
                ->danger()
                ->send();
        }
    }

    public function upgradeYearly()
    {
        $tenant = Filament::getTenant();

        if (! $tenant || $tenant->subscribed('default')) {
            return;
        }

        try {
            return $tenant->newSubscription('default', 'price_1T9PueCqOt3Mipp1qjPMUx7i')
                ->checkout([
                    'success_url' => \App\Filament\Pages\Dashboard::getUrl(['tenant' => $tenant]),
                    'cancel_url' => self::getUrl(['tenant' => $tenant]),
                ]);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            \Filament\Notifications\Notification::make()
                ->title('Stripe not configured')
                ->body('The Stripe API key is not set. Please configure STRIPE_KEY and STRIPE_SECRET in your .env file.')
                ->danger()
                ->send();
        }
    }
}
