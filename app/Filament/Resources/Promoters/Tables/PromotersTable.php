<?php

namespace App\Filament\Resources\Promoters\Tables;

use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PromotersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer_no')
                    ->label('Kd.-Nr.')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('short_name')
                    ->label('Kürzel')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city')
                    ->label('Ort')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contacts_count')
                    ->label('Ansprechpartner')
                    ->counts('contacts')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-Mail')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Geändert')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
