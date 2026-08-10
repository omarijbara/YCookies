<?php

namespace App\Filament\Resources\GroupInvitations\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;

class GroupInvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('email')
                    ->label(__('ycookies.fields.email'))
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('role')
                    ->label(__('ycookies.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('ycookies.roles.'.$state)),
                \Filament\Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('ycookies.fields.expires_at'))
                    ->dateTime()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ycookies.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Action::make('copyUrl')
                    ->label(__('ycookies.copy_invite_link'))
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->action(function (\App\Models\GroupInvitation $record) {
                        // Action fires JS natively
                    })
                    ->extraAttributes(fn (\App\Models\GroupInvitation $record) => [
                        'x-data' => '',
                        'x-on:click' => "window.navigator.clipboard.writeText('".route('invitations.accept', ['token' => $record->token])."'); \$tooltip('Copied!')",
                    ]),
                Action::make('resend')
                    ->label(__('ycookies.resend_invite'))
                    ->icon('heroicon-o-envelope')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (\App\Models\GroupInvitation $record) {
                        \Illuminate\Support\Facades\Mail::to($record->email)->send(
                            new \App\Mail\GroupInvitationMail($record)
                        );
                        \Filament\Notifications\Notification::make()
                            ->title(__('ycookies.invitation_resent'))
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
