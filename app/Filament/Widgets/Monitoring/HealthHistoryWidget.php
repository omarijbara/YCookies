<?php

namespace App\Filament\Widgets\Monitoring;

use App\Models\HealthCheckResult;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class HealthHistoryWidget extends BaseWidget
{
    protected static ?string $heading = 'Recent Health Check History';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                HealthCheckResult::query()->latest('checked_at')
            )
            ->description('Complete historical log of all automated health checks across your domains.')
            ->columns([
                Tables\Columns\TextColumn::make('domain_name')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->getStateUsing(fn ($record) => $record->domain->name ?? 'Unknown'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'failing' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('checked_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Response Time')
                    ->numeric()
                    ->suffix(' ms'),
            ])
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->modalHeading('Health Check Analysis')
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn ($record) => view('filament.monitoring.health-check-results-modal', [
                        'latestResult' => $record->toArray(),
                        'aiDiagnosis' => $record->ai_diagnosis,
                    ])),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->paginated([5, 10, 25]);
    }
}
