<?php

namespace App\Filament\Resources\Promoters\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PromoterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Stammdaten')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('short_name')
                            ->label('Kürzel')
                            ->maxLength(120),
                        TextInput::make('customer_no')
                            ->label('Kundennummer')
                            ->helperText('5-stellig, Präfix der VA-ID')
                            ->maxLength(50),
                        TextInput::make('email')
                            ->label('E-Mail')
                            ->email()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Section::make('Adresse')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address1')
                            ->label('Straße')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('address2')
                            ->label('Adresszusatz')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('zip')
                            ->label('PLZ')
                            ->maxLength(20),
                        TextInput::make('city')
                            ->label('Ort')
                            ->maxLength(120),
                    ]),
                Section::make('Ansprechpartner')
                    ->schema([
                        Repeater::make('contacts')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->defaultItems(0)
                            ->addActionLabel('Ansprechpartner hinzufügen')
                            ->columns(5)
                            ->schema([
                                TextInput::make('first_name')->label('Vorname')->maxLength(120),
                                TextInput::make('last_name')->label('Nachname')->maxLength(120),
                                TextInput::make('role')->label('Funktion')->maxLength(120),
                                TextInput::make('phone')->label('Telefon')->tel()->maxLength(50),
                                TextInput::make('email')->label('E-Mail')->email()->maxLength(255),
                            ]),
                    ]),
                Toggle::make('is_archived')
                    ->label('Archiviert')
                    ->helperText('Archivierte Veranstalter erscheinen nicht mehr in Auswahllisten.'),
            ]);
    }
}
