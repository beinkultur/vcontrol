<?php

namespace App\Filament\Resources\Promoters\Tables;

use App\Filament\Resources\Promoters\PromoterResource;
use App\Models\Promoter;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                    ->visibleFrom('lg')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable(),
                // Lange Einträge brechen um – sonst wird die Liste breiter als der Bildschirm
                TextColumn::make('short_name')
                    ->label('Kürzel')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city')
                    ->label('Ort')
                    ->visibleFrom('md')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contacts_count')
                    ->label('Ansprechpartner')
                    ->visibleFrom('md')
                    ->counts('contacts')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-Mail')
                    ->visibleFrom('lg')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Geändert')
                    ->visibleFrom('lg')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->recordActions([
                // Ansehen nur für Leserollen – wer bearbeiten darf, landet direkt im Formular
                ViewAction::make()->hidden(fn (Promoter $record): bool => PromoterResource::canEdit($record)),
                EditAction::make(),
            ]);
    }
}
