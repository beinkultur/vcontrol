<?php

namespace App\Filament\Support;

use App\Filament\Resources\Costings\CostingResource;
use App\Filament\Resources\Costings\Pages\EditCosting;
use App\Models\Costing;
use App\Models\Event;
use App\Models\PriceList;
use App\Models\User;
use App\Support\Costings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Component;
use Throwable;

/**
 * Aktionen rund ums Costing – im Event (Buchung › Costing) und auf den Seiten
 * des Costings: erstellen, PDF, verschicken, neue Fassung. Rechte:
 * CostingPolicy (anlegen = Events bearbeiten).
 */
final class CostingActions
{
    /** Neues Costing aus einer Preisliste, vorbelegt mit der des Events; danach geht es ins Costing. */
    public static function create(): Action
    {
        return Action::make('createCosting')
            ->label('Costing erstellen')
            ->icon(Heroicon::OutlinedCalculator)
            ->authorize(fn (): bool => CostingResource::canCreate())
            ->modalHeading('Costing erstellen')
            ->modalDescription('Kostenpunkte, Preise und Vorgabe-Faktoren kommen aus der Preisliste, Posten „pro verkauftem Ticket“ mit den erwarteten PAX. Danach lassen sich Faktoren anpassen und Posten ergänzen.')
            ->modalSubmitActionLabel('Erstellen')
            ->fillForm(fn (Component $livewire): array => ['price_list_id' => self::event($livewire)->price_list_id])
            ->schema(fn (Component $livewire): array => [
                Select::make('price_list_id')
                    ->label('Preisliste')
                    ->options(PriceList::choices(self::event($livewire)->price_list_id))
                    ->placeholder('ohne – Posten selbst eintragen')
                    ->native(false),
            ])
            ->action(function (array $data, Component $livewire): void {
                $event = self::event($livewire);
                $list = filled($data['price_list_id'] ?? null) ? PriceList::query()->find($data['price_list_id']) : null;
                $costing = Costing::createFor($event, $list);

                $livewire->redirect(CostingResource::getUrl('edit', ['record' => $costing]));
            });
    }

    public static function pdf(): Action
    {
        return Action::make('pdf')
            ->label('PDF')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->url(fn (Costing $record): string => route('costings.pdf', $record), shouldOpenInNewTab: true);
    }

    /**
     * Verschicken mit dem PDF im Anhang. Auf der Bearbeiten-Seite wird vorher
     * gespeichert, damit das PDF zeigt, was im Formular steht.
     */
    public static function send(): Action
    {
        return Action::make('sendCosting')
            ->label(fn (Costing $record): string => $record->isSent() ? 'Erneut verschicken' : 'Verschicken')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize(fn (): bool => CostingResource::canCreate())
            ->modalHeading(fn (Costing $record): string => $record->label() . ' verschicken')
            ->modalDescription(fn (Costing $record): string => 'Das Costing geht als PDF im Anhang an die Empfänger. '
                . ($record->isSent() ? 'Diese Fassung ist schon verschickt und bleibt, wie sie ist.' : 'Danach bleibt diese Fassung, wie sie ist – Änderungen gehen als neue Fassung.'))
            ->modalSubmitActionLabel('Verschicken')
            ->modalWidth('3xl')
            ->fillForm(function (Costing $record): array {
                $user = Auth::user();

                return Costings::defaults($record, $user instanceof User ? $user : null);
            })
            ->schema([
                TagsInput::make('to')
                    ->label('An')
                    ->placeholder('E-Mail-Adresse und Enter')
                    ->helperText('Vorbelegt: die Ansprechpartner des Veranstalters.')
                    ->splitKeys(['Tab', ',', ' ', ';'])
                    ->nestedRecursiveRules(['email'])
                    ->required(),
                TagsInput::make('bcc')
                    ->label('BCC')
                    ->placeholder('E-Mail-Adresse und Enter')
                    ->helperText('Vorbelegt: du selbst, als Kopie.')
                    ->splitKeys(['Tab', ',', ' ', ';'])
                    ->nestedRecursiveRules(['email']),
                TextInput::make('subject')
                    ->label('Betreff')
                    ->required()
                    ->maxLength(200),
                Textarea::make('body')
                    ->label('Text')
                    ->required()
                    ->rows(8),
            ])
            ->action(function (array $data, Costing $record, Component $livewire, Action $action): void {
                if ($livewire instanceof EditCosting) {
                    $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $record->refresh();
                }
                $user = Auth::user();
                try {
                    Costings::send($record, (array) $data['to'], (array) ($data['bcc'] ?? []), (string) $data['subject'], (string) $data['body'], $user instanceof User ? $user : null);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Versand fehlgeschlagen')->body('Die Mail ging nicht raus, das Costing ist unverändert. Bitte später noch einmal versuchen.')->persistent()->send();
                    $action->halt();
                }

                Notification::make()->success()->title($record->label() . ' verschickt')->body('An ' . implode(', ', (array) $record->sent_to) . '.')->send();
                if ($livewire instanceof EditCosting) {
                    $livewire->redirect(CostingResource::getUrl('view', ['record' => $record]));
                }
            });
    }

    /** Kopie als neuer Entwurf – der Weg, eine verschickte Fassung zu ändern. */
    public static function duplicate(): Action
    {
        return Action::make('duplicateCosting')
            ->label('Neue Fassung')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->authorize(fn (): bool => CostingResource::canCreate())
            ->requiresConfirmation()
            ->modalHeading('Neue Fassung anlegen')
            ->modalDescription(fn (Costing $record): string => 'Kopiert ' . $record->label() . ' mit allen Posten als neuen Entwurf.')
            ->action(function (Costing $record, Component $livewire): void {
                $livewire->redirect(CostingResource::getUrl('edit', ['record' => $record->duplicate()]));
            });
    }

    /** Das Event der Seite: Workspace (Relation Manager) */
    private static function event(Component $livewire): Event
    {
        $event = method_exists($livewire, 'getOwnerRecord') ? $livewire->getOwnerRecord() : null;
        if (!$event instanceof Event) {
            throw new InvalidArgumentException('Costing erstellen geht nur am Event.');
        }

        return $event;
    }
}
