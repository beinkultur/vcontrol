<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Support\GuestLists;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Gästeliste eines Events mit Freikarten. In der Detailansicht nur lesend.
 * Drucken aus dem Browser, als PDF (immer DIN A4) und seit 19.10.2026 per Mail
 * mit dem PDF im Anhang (GuestLists) – verschicken nur, wer das Event bearbeitet.
 */
class GuestsRelationManager extends RelationManager
{
    protected static string $relationship = 'guests';

    protected static ?string $title = 'Gästeliste';

    protected static ?string $modelLabel = 'Gast';

    protected static ?string $pluralModelLabel = 'Gäste';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('first_name')
                    ->label('Vorname')
                    ->required()
                    ->maxLength(120),
                TextInput::make('last_name')
                    ->label('Name')
                    ->required()
                    ->maxLength(120),
                TextInput::make('free_tickets')
                    ->label('Freikarten')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999)
                    ->default(0)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('last_name')
            ->columns([
                TextColumn::make('last_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label('Vorname')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('free_tickets')
                    ->label('Freikarten')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('Gesamt')),
            ])
            ->defaultSort('last_name')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->headerActions([
                Action::make('print')
                    ->label('Drucken')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (): string => route('events.guest-list-print', $this->getOwnerRecord()))
                    ->openUrlInNewTab(),
                Action::make('pdf')
                    ->label('PDF (A4)')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('gray')
                    ->url(fn (): string => route('events.guest-list-pdf', $this->getOwnerRecord()))
                    ->openUrlInNewTab(),
                $this->sendAction(),
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $data + ['created_by' => Auth::id()]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** Per Mail: An, BCC, Betreff, Text (vorbelegt, mit Platzhaltern), das PDF hängt an */
    private function sendAction(): Action
    {
        return Action::make('sendGuestList')
            ->label('Per Mail senden')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->modalHeading('Gästeliste per Mail senden')
            ->modalDescription('Die Gästeliste geht als PDF (DIN A4) im Anhang mit. Antworten gehen an die Antwortadresse der Venue, sonst an dich.')
            ->modalSubmitActionLabel('Versenden')
            ->modalWidth('3xl')
            ->visible(fn (): bool => !$this->isReadOnly() && EventResource::canEdit($this->event()))
            ->disabled(fn (): bool => !$this->event()->guests()->exists())
            ->tooltip(fn (): ?string => $this->event()->guests()->exists() ? null : 'Noch keine Gäste eingetragen')
            ->fillForm(fn (): array => GuestLists::defaults($this->event(), Auth::user() instanceof User ? Auth::user() : null))
            ->schema([
                TagsInput::make('to')
                    ->label('An')
                    ->placeholder('E-Mail-Adresse und Enter')
                    ->helperText('Etwa der Einlass oder die Abendkasse.')
                    ->splitKeys(['Tab', ',', ' ', ';'])
                    ->nestedRecursiveRules(['email'])
                    ->required(),
                TagsInput::make('bcc')
                    ->label('BCC')
                    ->placeholder('E-Mail-Adresse und Enter')
                    ->helperText('Vorbelegt: du selbst, für deine Unterlagen.')
                    ->splitKeys(['Tab', ',', ' ', ';'])
                    ->nestedRecursiveRules(['email']),
                TextInput::make('subject')
                    ->label('Betreff')
                    ->required()
                    ->maxLength(200),
                Textarea::make('body')
                    ->label('Text')
                    ->required()
                    ->rows(8)
                    ->helperText(GuestLists::PLACEHOLDER_HELP),
            ])
            ->action(function (array $data, Action $action): void {
                $event = $this->event();
                abort_unless(!$this->isReadOnly() && EventResource::canEdit($event), 403);
                $sender = Auth::user();

                try {
                    GuestLists::send($event, (array) ($data['to'] ?? []), (array) ($data['bcc'] ?? []), (string) $data['subject'], (string) $data['body'],
                        $sender instanceof User ? $sender : null);
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Gästeliste verschickt an ' . implode(', ', (array) $data['to']))->send();
            });
    }

    private function event(): Event
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();

        return $event;
    }
}
