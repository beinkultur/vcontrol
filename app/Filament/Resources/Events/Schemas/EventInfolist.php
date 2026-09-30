<?php

namespace App\Filament\Resources\Events\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Vorläufige Detailansicht der Event-Kerndaten, bis der Workspace steht. */
class EventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Buchung')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('title')->label('Titel')->columnSpanFull(),
                        TextEntry::make('promoter.name')->label('Veranstalter')->placeholder('–'),
                        TextEntry::make('va_id')->label('VA-ID')->placeholder('–'),
                        TextEntry::make('starts_at')->label('Beginn')->dateTime('D, d.m.Y H:i'),
                        TextEntry::make('ends_at')->label('Ende')->dateTime('D, d.m.Y H:i')->placeholder('–'),
                        TextEntry::make('event_type1')->label('Kategorie 1')->placeholder('–'),
                        TextEntry::make('event_type2')->label('Kategorie 2')->placeholder('–'),
                        TextEntry::make('description')->label('Beschreibung')->placeholder('–')->columnSpanFull(),
                        TextEntry::make('booking_notes')->label('Interne Buchungsnotizen')->placeholder('–')->columnSpanFull(),
                    ]),
                Section::make('Status')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')->label('VA-Status')->badge(),
                        IconEntry::make('doing_closed')->label('Durchführung abgeschlossen')->boolean(),
                        IconEntry::make('closed')->label('Event abgeschlossen')->boolean(),
                    ]),
                Section::make('Halle')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('areas')->label('Bereiche')->badge()->placeholder('–'),
                        TextEntry::make('seating')->label('Bestuhlung')->badge()->placeholder('–'),
                        TextEntry::make('ticketing')->label('Ticketing')->placeholder('–'),
                        TextEntry::make('pax_expected')->label('PAX erwartet')->placeholder('–'),
                        TextEntry::make('pax')->label('PAX abgerechnet')->placeholder('–'),
                        TextEntry::make('onsite_contact')->label('Ansprechpartner vor Ort')->placeholder('–'),
                        TextEntry::make('wlan')->label('WLAN')->placeholder('–'),
                    ]),
            ]);
    }
}
