<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Rolle')
                    ->description(fn (Role $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Kennung')
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('users_count')
                    ->label('Benutzer')
                    ->counts('users')
                    ->alignCenter()
                    ->sortable(),
                IconColumn::make('is_super')
                    ->label('Vollzugriff')
                    ->boolean()
                    ->alignCenter(),
                IconColumn::make('is_system')
                    ->label('Systemrolle')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->defaultSort('sort_order')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
