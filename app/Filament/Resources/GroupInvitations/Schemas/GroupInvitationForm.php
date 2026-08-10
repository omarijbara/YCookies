<?php

namespace App\Filament\Resources\GroupInvitations\Schemas;

use Filament\Schemas\Schema;

class GroupInvitationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Select::make('group_id')
                    ->label(__('ycookies.fields.group'))
                    ->helperText(__('ycookies.group_invitation.help_group'))
                    ->relationship('group', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                \Filament\Forms\Components\TextInput::make('email')
                    ->label(__('ycookies.fields.email'))
                    ->helperText(__('ycookies.group_invitation.help_email'))
                    ->email()
                    ->required()
                    ->maxLength(255),
                \Filament\Forms\Components\Select::make('role')
                    ->label(__('ycookies.fields.role'))
                    ->helperText(__('ycookies.group_invitation.help_role'))
                    ->options([
                        'member' => __('ycookies.roles.member'),
                        'admin' => __('ycookies.roles.admin'),
                    ])
                    ->required()
                    ->default('member'),
            ]);
    }
}
