<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class HealthChecker extends Page
{
    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.health-checker';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Health Checker';

    protected static ?string $title = 'Health Checker';

    protected static ?string $slug = 'health-checker';

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.tools');
    }

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.health_checker');
    }
}
