<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->label('Name')
                    ->formatStateUsing(fn (string $state, User $record): string => $record->getFilamentName())
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-Mail')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('roles.name')
                    ->label('Rollen')
                    ->badge(),
                TextColumn::make('account_type')
                    ->label('Kontotyp'),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->defaultSort('last_name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Aktiv'),
                SelectFilter::make('roles')
                    ->label('Rolle')
                    ->relationship('roles', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
