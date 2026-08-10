<?php

namespace App\Filament\Exports;

use App\Models\ConsentLog;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

use Filament\Actions\Exports\Enums\ExportFormat;

class ConsentLogExporter extends Exporter
{
    protected static ?string $model = ConsentLog::class;

    public function getFormats(): array
    {
        return [
            ExportFormat::Csv,
            ExportFormat::Xlsx,
        ];
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('domain.name'),
            ExportColumn::make('consent_uid'),
            ExportColumn::make('ip_hash'),
            ExportColumn::make('user_agent'),
            ExportColumn::make('consents_granted')
                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state) : $state),
            ExportColumn::make('services_granted')
                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state) : $state),
            ExportColumn::make('consent_type'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
            ExportColumn::make('cookie_version'),
            ExportColumn::make('is_latest')
                ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
            ExportColumn::make('tc_string'),
            ExportColumn::make('provider_overrides')
                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state) : $state),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your consent log export has completed and ' . Number::format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
