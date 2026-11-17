<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Access\Module;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\AdvancingFields;
use App\Models\AdvancingTemplate;
use App\Models\Event;
use App\Models\EventAdvancing;
use App\Models\User;
use App\Support\Advancings;
use App\Support\Daysheets;
use App\Support\MailIdentity;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * Planung › Advancing (Modul Advancing): Fragenkatalog an den Veranstalter.
 * Eine Zeile je Event – starten, vorbelegen, per Mail senden, Link kopieren,
 * sperren, abschließen. Was der Veranstalter abweichend eingetragen hat, steht
 * beim Öffnen als Hinweis am Feld (App\Filament\Support\AdvancingFields).
 */
class AdvancingRelationManager extends RelationManager
{
    protected static string $relationship = 'advancings';

    protected static ?string $title = 'Advancing';

    protected static ?string $modelLabel = 'Advancing';

    protected static ?string $pluralModelLabel = 'Advancing';

    /** Mit dem Modul für alle, die das Event sehen – Filament prüfte sonst nur viewAny der Policy */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Module::Advancing->isEnabled() && Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('template_name')
                    ->label('Katalog'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (EventAdvancing $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'abgegeben' => 'success',
                        'versendet', 'geöffnet' => 'info',
                        'gesperrt', 'abgelaufen' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('sent_at')
                    ->label('Versendet')
                    ->state(fn (EventAdvancing $record): ?string => $record->sent_at
                        ? $record->sent_at->format('d.m.Y H:i') . ' an ' . implode(', ', (array) $record->recipients_to)
                        : null)
                    ->placeholder('–')
                    ->wrap(),
                TextColumn::make('expires_at')
                    ->label('Gültig bis')
                    ->dateTime('d.m.Y H:i')
                    ->visibleFrom('md')
                    ->placeholder('–'),
                TextColumn::make('submitted_at')
                    ->label('Abgegeben')
                    ->state(fn (EventAdvancing $record): ?string => $record->submitted_at
                        ? $record->submitted_at->format('d.m.Y H:i') . ($record->submit_count > 1 ? " ({$record->submit_count}×)" : '')
                        : null)
                    ->placeholder('–'),
                TextColumn::make('history')
                    ->label('Verlauf')
                    ->state(fn (EventAdvancing $record): ?string => implode(' · ', array_filter([
                        $record->first_opened_at ? 'Geöffnet ' . $record->first_opened_at->format('d.m. H:i') : null,
                        $record->last_saved_at ? 'Gespeichert ' . $record->last_saved_at->format('d.m. H:i') : null,
                        $record->closed_at ? 'Abgeschlossen ' . $record->closed_at->format('d.m. H:i') . (filled($record->closed_by_name) ? " ({$record->closed_by_name})" : '') : null,
                    ])) ?: null)
                    ->placeholder('–')
                    ->wrap()
                    ->visibleFrom('lg'),
                TextColumn::make('changed')
                    ->label('Geändert')
                    ->state(fn (EventAdvancing $record): int => count(Advancings::changedKeys($record)))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->visibleFrom('md'),
            ])
            ->headerActions([
                Action::make('startAdvancing')
                    ->label('Advancing starten')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->modalHeading('Advancing starten')
                    ->modalDescription('Was im Event schon steht (Titel, Datum, Zeiten, Ansprechpartner vor Ort), ist danach vorbelegt.')
                    ->modalSubmitActionLabel('Starten')
                    ->visible(fn (): bool => $this->mayChange() && !$this->event()->advancing()->exists())
                    ->schema([
                        Select::make('template')
                            ->label('Fragenkatalog')
                            ->options(fn (): array => AdvancingTemplate::query()->active()->pluck('name', 'id')->all())
                            ->default(fn (): ?int => AdvancingTemplate::query()->active()->count() === 1 ? AdvancingTemplate::query()->active()->value('id') : null)
                            ->required(),
                    ])
                    ->action(function (array $data, Action $action): void {
                        abort_unless($this->mayChange(), 403);
                        $template = AdvancingTemplate::query()->active()->find($data['template'] ?? null);
                        if ($template === null) {
                            Notification::make()->danger()->title('Diesen Katalog gibt es nicht mehr.')->send();
                            $action->halt();

                            return;
                        }
                        try {
                            Advancings::start($this->event(), $template);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                            $action->halt();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Öffnen')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->modalHeading(fn (EventAdvancing $record): string => 'Advancing „' . $record->template_name . '“')
                    ->modalDescription('Vorbelegung für den Veranstalter. Was er abweichend eingetragen hat, steht gelb am Feld.')
                    ->modalSubmitActionLabel('Vorbelegung speichern')
                    ->visible(fn (): bool => $this->mayChange())
                    ->fillForm(fn (EventAdvancing $record): array => AdvancingFields::fill($record))
                    ->schema(fn (EventAdvancing $record): array => AdvancingFields::schema($record))
                    ->action(function (EventAdvancing $record, array $data): void {
                        abort_unless($this->mayChange(), 403);
                        Advancings::updatePrefill($record, AdvancingFields::prefill($record, $data));
                        Notification::make()->success()->title('Vorbelegung gespeichert')->send();
                    }),
                Action::make('view')
                    ->label('Ansehen')
                    ->icon(Heroicon::OutlinedEye)
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->modalHeading(fn (EventAdvancing $record): string => 'Advancing „' . $record->template_name . '“')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schließen')
                    ->visible(fn (): bool => !$this->mayChange())
                    // Nur Text, keine Auswahllisten: die Personenliste mit Kontaktdaten bleibt draußen
                    ->schema(fn (EventAdvancing $record): array => AdvancingFields::schema($record, editable: false)),
                Action::make('send')
                    ->label('Per Mail senden')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('gray')
                    ->modalHeading('Fragenkatalog per Mail senden')
                    ->modalDescription(fn (EventAdvancing $record): string => 'Die Empfänger bekommen einen Link ohne Anmeldung – '
                        . self::validity($record) . '. Wer den Link hat, kann den Katalog ausfüllen. '
                        . 'Antworten auf die Mail gehen an ' . (MailIdentity::replyTo()?->address ?? 'dich') . '.')
                    ->modalSubmitActionLabel('Senden')
                    ->modalWidth('3xl')
                    ->visible(fn (EventAdvancing $record): bool => $this->mayChange() && !$record->isClosed())
                    ->fillForm(fn (EventAdvancing $record): array => Advancings::defaults($record))
                    ->schema([
                        TagsInput::make('to')
                            ->label('An')
                            ->placeholder('E-Mail-Adresse und Enter')
                            ->helperText('Vorbelegt: Ansprechpartner vor Ort (Planung › Kontakte).')
                            ->splitKeys(['Tab', ',', ' ', ';'])
                            ->nestedRecursiveRules(['email'])
                            ->required(),
                        TagsInput::make('bcc')
                            ->label('BCC')
                            ->placeholder('E-Mail-Adresse und Enter')
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
                            ->helperText(Advancings::PLACEHOLDER_HELP),
                    ])
                    ->action(function (EventAdvancing $record, array $data, Action $action): void {
                        abort_unless($this->mayChange(), 403);
                        $sender = Auth::user();
                        try {
                            Advancings::send(
                                $record,
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
                        } catch (Throwable $exception) {
                            // Mailserver lehnt ab (etwa falsches Passwort in der .env): wie beim Costing melden, nichts gespeichert
                            report($exception);
                            Notification::make()
                                ->danger()
                                ->title('Versand fehlgeschlagen')
                                ->body('Die Mail ging nicht raus, der Link wurde nicht verschickt. Bitte später noch einmal versuchen oder dem Betreiber Bescheid geben.')
                                ->persistent()
                                ->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()
                            ->success()
                            ->title('Fragenkatalog verschickt')
                            ->body('Gültig bis ' . $record->expires_at->format('d.m.Y, H:i') . ' Uhr.')
                            ->send();
                    }),
                Action::make('copyLink')
                    ->label('Link')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->modalHeading('Link zum Fragenkatalog')
                    ->modalDescription('Wer diesen Link hat, kann den Katalog ohne Anmeldung ausfüllen – nur an den Veranstalter weitergeben.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schließen')
                    ->visible(fn (EventAdvancing $record): bool => $this->mayChange() && $record->isLinkValid())
                    ->fillForm(fn (EventAdvancing $record): array => ['link' => $record->link()])
                    ->schema([
                        TextInput::make('link')
                            ->label('Link')
                            ->readOnly()
                            ->copyable(copyMessage: 'Kopiert'),
                    ]),
                Action::make('open')
                    ->label('Als Veranstalter ansehen')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (EventAdvancing $record): bool => $this->mayChange() && $record->isLinkValid())
                    ->url(fn (EventAdvancing $record): ?string => $record->link(), shouldOpenInNewTab: true),
                Action::make('revoke')
                    ->label('Link sperren')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Der Link funktioniert danach nicht mehr. Erneutes Senden verschickt einen neuen Link.')
                    ->visible(fn (EventAdvancing $record): bool => $this->mayChange() && $record->isSent() && !$record->isRevoked())
                    ->action(function (EventAdvancing $record): void {
                        abort_unless($this->mayChange(), 403);
                        $record->update(['revoked_at' => now()]);
                    }),
                Action::make('unrevoke')
                    ->label('Sperre aufheben')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Der bisherige Link funktioniert dann wieder.')
                    ->visible(fn (EventAdvancing $record): bool => $this->mayChange() && $record->isRevoked())
                    ->action(function (EventAdvancing $record): void {
                        abort_unless($this->mayChange(), 403);
                        $record->update(['revoked_at' => null]);
                    }),
                Action::make('close')
                    ->label('Abschließen')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Der Veranstalter sieht seine Angaben danach nur noch und kann nichts mehr ändern.')
                    ->visible(fn (EventAdvancing $record): bool => $this->mayChange() && !$record->isClosed())
                    ->action(function (EventAdvancing $record): void {
                        abort_unless($this->mayChange(), 403);
                        $user = Auth::user();
                        $record->update(['closed_at' => now(), 'closed_by_name' => $user instanceof User ? $user->getFilamentName() : null]);
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Noch kein Advancing')
            ->emptyStateDescription('Katalog wählen und starten – was im Event schon steht, ist vorbelegt.');
    }

    private function event(): Event
    {
        $event = $this->getOwnerRecord();
        abort_unless($event instanceof Event, 404);

        return $event;
    }

    /** Ändern nur mit Bearbeiten-Recht am Event und nicht in der Lese-Ansicht. */
    private function mayChange(): bool
    {
        return !$this->isReadOnly() && Module::Advancing->isEnabled() && EventResource::canEdit($this->event());
    }

    private static function validity(EventAdvancing $advancing): string
    {
        $until = Daysheets::validUntil($advancing->event);

        return match (true) {
            $until === null => 'das Event braucht dafür noch ein Datum',
            $until->isPast() => 'die Veranstaltung ist vorbei, ein neuer Link wäre schon abgelaufen',
            default => 'gültig bis ' . $until->format('d.m.Y, H:i') . ' Uhr (Tag nach der Veranstaltung)',
        };
    }
}
