<?php

namespace App\Filament\Resources\TrafficAlerts\Pages;

use App\Filament\Resources\TrafficAlerts\TrafficAlertResource;
use Filament\Resources\Pages\ListRecords;

class ListTrafficAlerts extends ListRecords
{
    protected static string $resource = TrafficAlertResource::class;

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.list.traffic_alerts');
    }
}
