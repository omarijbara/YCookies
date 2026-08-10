<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class QuickActionsWidget extends Widget
{
    protected static ?int $sort = 6; // Below Traffic Charts

    protected string $view = 'filament.widgets.quick-actions-widget';

    protected int | string | array $columnSpan = 'full';
}
