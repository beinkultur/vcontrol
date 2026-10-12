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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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
                    ->visibleFrom('lg')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('roles.name')
                    ->label('Rollen')
                    ->badge(),
                TextColumn::make('account_type')
                    ->label('Kontotyp')
                    ->visibleFrom('md'),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->visibleFrom('md')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('last_login_at')
                    ->label('Zuletzt angemeldet')
                    ->visibleFrom('lg')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('–')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('last_name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Aktiv'),
                SelectFilter::make('roles')
                    ->label('Rolle')
                    ->relationship('roles', 'name', fn (Builder $query): Builder => $query->visibleTo(Auth::user() instanceof User ? Auth::user() : null)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
