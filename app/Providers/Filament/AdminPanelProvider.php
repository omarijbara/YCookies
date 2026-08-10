<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('YCookies')
            ->favicon(asset('favicon.ico'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->globalSearch(\App\Filament\GlobalSearch\YDevGlobalSearchProvider::class)
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->databaseNotifications()
            ->tenant(\App\Models\Group::class)
            ->tenantRegistration(\App\Filament\Pages\Tenancy\RegisterGroup::class)
            // TODO: re-enable after STRIPE_KEY + STRIPE_SECRET are set in Coolify
            // ->tenantBillingProvider(new \Maartenpaauw\Filament\Cashier\Stripe\BillingProvider('default'))
            // ->requiresTenantSubscription()
            ->defaultThemeMode(\Filament\Enums\ThemeMode::Dark)
            ->font('Inter')
            ->colors([
                'primary' => Color::Amber,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->renderHook(
                \Filament\View\PanelsRenderHook::STYLES_AFTER,
                fn () => '<style>
                    /* Reposition dashboard filters inline with header */
                    .fi-page-header-main-ctn { position: relative; }
                    [wire\\:partial="table-filters-form"] {
                        position: absolute;
                        top: 1.25rem;
                        right: 0;
                        z-index: 20;
                        max-width: 40rem;
                    }
                    /* Ensure the form displays horizontally */
                    [wire\\:partial="table-filters-form"] form > div {
                        display: flex;
                        gap: 1rem;
                        align-items: center;
                    }
                </style>',
            )
            ->navigationItems([
                \Filament\Navigation\NavigationItem::make('Documentation')
                    ->url(fn () => url('/docs'), shouldOpenInNewTab: false)
                    ->icon('heroicon-o-book-open')
                    ->group(fn () => __('ycookies.nav.system'))
                    ->sort(90),
            ])
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make()
                    ->label(fn () => __('ycookies.nav.workspace')),
                \Filament\Navigation\NavigationGroup::make()
                    ->label(fn () => __('ycookies.nav.consent_management')),
                \Filament\Navigation\NavigationGroup::make()
                    ->label(fn () => __('ycookies.nav.domains_proxy')),
                \Filament\Navigation\NavigationGroup::make()
                    ->label(fn () => __('ycookies.nav.tools'))
                    ->collapsed(),
                \Filament\Navigation\NavigationGroup::make()
                    ->label(fn () => __('ycookies.nav.settings'))
                    ->collapsed(),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('Filament Shield')
                    ->collapsed(),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
            ])
            ->plugins([
                \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
