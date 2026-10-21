<?php

namespace App\Filament\Support;

use App\Access\Area;
use App\Access\Level;
use App\Enums\OptionField;
use App\Models\Event;
use App\Models\EventFinance;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;

/**
 * Die Felder der Buchhaltung eines Events: im Workspace unter Buchung ›
 * Buchhaltung und im Buchhaltungs-Dashboard (seit 21.10.2026, dort auch für
 * Rollen, die Events nur lesen). Bearbeiten nur mit Buchhaltung „Bearbeiten“.
 * PAX abgerechnet steht seit 21.10.2026 hier statt unter Durchführung › Check.
 */
final class AccountingFields
{
    /** @return list<Section> */
    public static function all(): array
    {
        $readOnly = fn (): bool => !self::canEdit();

        return [
            Section::make('Finanzen')
                ->relationship('finance')
                ->columns(2)
                ->disabled($readOnly)
                ->schema([
                    Select::make('contract_status')
                        ->label('Vertragsstatus')
                        ->options(fn (?EventFinance $record): array => OptionChoices::for(OptionField::ContractStatus, $record?->contract_status))
                        ->native(false),
                    // Die Preisliste steht seit 13.10.2026 unter Buchung › Daten (Modul Preislisten & Costing)
                    TextInput::make('rent')
                        ->label('Miete')
                        ->numeric()
                        ->suffix('€'),
                    CheckboxList::make('accounting_status')
                        ->label('FIBU-Status')
                        ->options(fn (?EventFinance $record): array => OptionChoices::for(OptionField::AccountingStatus, $record?->accounting_status))
                        ->columns(2)
                        ->columnSpanFull(),
                    TagsInput::make('invoice_numbers')
                        ->label('Rechnungsnummern'),
                    Toggle::make('accounting_closed')
                        ->label('Abrechnung abgeschlossen'),
                ]),
            Section::make('Besucher')
                ->columns(2)
                ->disabled($readOnly)
                ->schema([
                    TextEntry::make('pax_expected_info')
                        ->label('PAX erwartet')
                        ->state(fn (?Event $record): string => $record?->pax_expected !== null ? number_format($record->pax_expected, 0, ',', '.') : '–'),
                    TextInput::make('pax')
                        ->label('PAX abgerechnet')
                        ->numeric()
                        ->minValue(0),
                ]),
            Section::make('Eingangsrechnungen')
                ->description('Welche Rechnungen von Dienstleistern erwartet werden und ob sie da sind. Mobiliar ist bei „bestuhlt“ automatisch erwartet, Cobra – Haus-Delay bei Haus-Delay (Durchführung › Betrieb) immer.')
                ->disabled($readOnly)
                ->schema(IncomingInvoiceFields::all()),
        ];
    }

    public static function canEdit(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Buchhaltung, Level::Edit);
    }
}
