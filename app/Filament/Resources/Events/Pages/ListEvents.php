<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Access\Area;
use App\Models\User;
use App\Support\EventIcsFeed;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/** Offen/Abgeschlossen/Alle steht wie in der PHP-Version im Filter „Status“, nicht in Reitern. */
class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    /** Kein Pfad über der Liste – jede Zeile zählt. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendarFeed')
                ->label('Kalender abonnieren')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->visible(fn (): bool => EventIcsFeed::isEnabled() && self::canUseCalendar())
                ->modalHeading('Kalender abonnieren')
                ->modalDescription('Alle bestätigten Veranstaltungen als Abo: diese Adresse in Outlook, Google Kalender oder Apple Kalender als Kalender-Abo hinzufügen. Sie enthält einen geheimen Schlüssel – bitte nicht weitergeben.')
                ->schema([
                    TextInput::make('url')
                        ->label('Adresse')
                        ->default(fn (): ?string => EventIcsFeed::url())
                        ->readOnly()
                        ->copyable(copyMessage: 'Adresse kopiert'),
                ])
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Schließen'),
            CreateAction::make()->label('Neues Event'),
        ];
    }

    private static function canUseCalendar(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Kalender);
    }
}
