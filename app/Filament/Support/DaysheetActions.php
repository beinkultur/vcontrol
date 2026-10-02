<?php

namespace App\Filament\Support;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Support\Daysheets;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * „Daysheet versenden“ – im Kopf des Events und unter Durchführung › Daysheets.
 * Vorbelegt mit den Standards der Halle und den beteiligten Gewerken im BCC;
 * alles lässt sich vor dem Versand ändern. Nur für Bearbeiter des Events.
 */
final class DaysheetActions
{
    public static function send(): Action
    {
        return Action::make('sendDaysheet')
            ->label('Daysheet')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->modalHeading('Daysheet versenden')
            ->modalDescription(fn ($livewire): string => 'Die Empfänger bekommen einen Link auf Zeiten, Ansprechpartner, Gewerke, Bühne, Dateien und Notizen – '
                . self::validity(self::event($livewire)) . ', zum Drucken oder als PDF. Für Externe verborgene Dateien und Notizen fehlen darin.')
            ->modalSubmitActionLabel('Versenden')
            ->modalWidth('3xl')
            ->visible(fn ($livewire): bool => EventResource::canEdit(self::event($livewire)))
            ->fillForm(fn ($livewire): array => Daysheets::defaults(self::event($livewire)))
            ->schema([
                TagsInput::make('to')
                    ->label('An')
                    ->placeholder('E-Mail-Adresse und Enter')
                    ->helperText('Vorbelegt: Standard-Empfänger der Halle (Verwaltung › Halle).')
                    ->splitKeys(['Tab', ',', ' ', ';'])
                    ->nestedRecursiveRules(['email'])
                    ->required(),
                TagsInput::make('bcc')
                    ->label('BCC')
                    ->placeholder('E-Mail-Adresse und Enter')
                    ->helperText('Vorbelegt: die beteiligten Gewerke mit E-Mail-Adresse.')
                    ->splitKeys(['Tab', ',', ' ', ';'])
                    ->nestedRecursiveRules(['email']),
                TextInput::make('subject')
                    ->label('Betreff')
                    ->required()
                    ->maxLength(200),
                Textarea::make('body')
                    ->label('Text')
                    ->required()
                    ->rows(10)
                    ->helperText(Daysheets::PLACEHOLDER_HELP),
            ])
            ->extraModalFooterActions(fn ($livewire): array => [
                Action::make('previewDaysheet')
                    ->label('Vorschau')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(route('events.daysheet-preview', self::event($livewire)), shouldOpenInNewTab: true),
            ])
            ->action(function (array $data, $livewire, Action $action): void {
                $event = self::event($livewire);
                abort_unless(EventResource::canEdit($event), 403);
                $sender = Auth::user();

                try {
                    $daysheet = Daysheets::send(
                        $event,
                        (array) ($data['to'] ?? []),
                        (array) ($data['bcc'] ?? []),
                        (string) $data['subject'],
                        (string) $data['body'],
                        $sender instanceof User ? $sender : null,
                    );
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Daysheet verschickt')
                    ->body('An ' . count($daysheet->allRecipients()) . ' Empfänger, gültig bis ' . $daysheet->expires_at->format('d.m.Y, H:i') . ' Uhr.')
                    ->send();
            });
    }

    private static function validity(Event $event): string
    {
        $until = Daysheets::validUntil($event);

        return match (true) {
            $until === null => 'das Event braucht dafür noch ein Datum',
            $until->isPast() => 'die Veranstaltung ist vorbei, ein neuer Link wäre schon abgelaufen',
            default => 'gültig ab jetzt bis ' . $until->format('d.m.Y, H:i') . ' Uhr (Tag nach der Veranstaltung)',
        };
    }

    /** Das Event der Seite oder – unter Durchführung › Daysheets – des Relation Managers. */
    private static function event(mixed $livewire): Event
    {
        $record = $livewire instanceof RelationManager ? $livewire->getOwnerRecord() : $livewire->getRecord();
        abort_unless($record instanceof Event, 404);

        return $record;
    }
}
