<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Forms\SignaturePad;
use App\Models\Event;
use App\Models\EventShowChecklist;
use App\Models\User;
use App\Support\ShowChecklist;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Durchführung › Checklisten wie die „EventsCheckliste“ in AppSheet: Material und
 * Kleinteile nach der Show prüfen, je mit Anmerkung, dazu House-Rep. und
 * Prom.-Rep. mit Unterschrift. Mehrere je Event möglich.
 */
class ShowChecklistsRelationManager extends RelationManager
{
    protected static string $relationship = 'showChecklists';

    protected static ?string $title = 'Checklisten';

    protected static ?string $modelLabel = 'Checkliste';

    protected static ?string $pluralModelLabel = 'Checklisten';

    /**
     * Auch in der Ansicht des Events bearbeitbar: Event-Operationen sind wie in der
     * PHP-Version ein eigenes Recht (siehe Policy), etwa für den Hausmeister, der
     * Events nur lesen darf.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                DateTimePicker::make('checked_at')
                    ->label('Zeitpunkt')
                    ->seconds(false)
                    ->default(fn () => now())
                    ->required(),
                TextInput::make('house_rep')
                    ->label('House-Rep.')
                    ->default(fn (): ?string => Auth::user() instanceof User ? Auth::user()->getFilamentName() : null)
                    ->maxLength(120),
                TextInput::make('promoter_rep')
                    ->label('Prom.-Rep.')
                    ->default(fn (): ?string => $this->event()->onsite_contact)
                    ->maxLength(120),
                ...array_map(
                    fn (string $group, array $items): Section => Section::make($group)
                        ->compact()
                        ->columnSpanFull()
                        ->schema([
                            Group::make(array_map(self::checkRow(...), array_keys($items), $items))
                                ->statePath('checks'),
                        ]),
                    array_keys(ShowChecklist::GROUPS),
                    ShowChecklist::GROUPS,
                ),
                Textarea::make('remarks')
                    ->label('Bemerkungen')
                    ->rows(3)
                    ->maxLength(5000)
                    ->columnSpanFull(),
                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        SignaturePad::make('house_rep_signature')->label('Unterschrift House-Rep.'),
                        SignaturePad::make('promoter_rep_signature')->label('Unterschrift Prom.-Rep.'),
                    ]),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('checked_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i'),
                TextEntry::make('house_rep')->label('House-Rep.')->placeholder('–'),
                TextEntry::make('promoter_rep')->label('Prom.-Rep.')->placeholder('–'),
                View::make('filament.events.show-checklist')
                    ->viewData(fn (EventShowChecklist $record): array => ['checks' => $record->checks ?? []])
                    ->columnSpanFull(),
                TextEntry::make('remarks')->label('Bemerkungen')->placeholder('–')->columnSpanFull(),
                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        View::make('filament.events.signature-image')
                            ->viewData(fn (EventShowChecklist $record): array => ['signature' => $record->house_rep_signature, 'label' => 'Unterschrift House-Rep.']),
                        View::make('filament.events.signature-image')
                            ->viewData(fn (EventShowChecklist $record): array => ['signature' => $record->promoter_rep_signature, 'label' => 'Unterschrift Prom.-Rep.']),
                    ]),
                TextEntry::make('created_by_name')->label('Erfasst von')->placeholder('–'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (EventShowChecklist $record): string => 'Checkliste vom ' . $record->checked_at->format('d.m.Y H:i'))
            ->defaultSort('checked_at', 'desc')
            ->columns([
                TextColumn::make('checked_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('house_rep')->label('House-Rep.')->placeholder('–'),
                TextColumn::make('promoter_rep')->label('Prom.-Rep.')->placeholder('–'),
                TextColumn::make('checked')
                    ->label('In Ordnung')
                    ->state(function (EventShowChecklist $record): string {
                        $counts = $record->counts();

                        return "{$counts['done']} / {$counts['total']}";
                    })
                    ->badge()
                    ->color(fn (EventShowChecklist $record): string => $record->counts()['done'] === $record->counts()['total'] ? 'success' : 'gray'),
                TextColumn::make('missing')
                    ->label('Fehlt')
                    ->state(fn (EventShowChecklist $record): ?string => $record->counts()['missing'] > 0 ? $record->counts()['missing'] . ' × Nein' : null)
                    ->badge()
                    ->color('danger')
                    ->placeholder('–'),
                IconColumn::make('signatures')
                    ->label('Unterschriften')
                    ->state(fn (EventShowChecklist $record): bool => filled($record->house_rep_signature) && filled($record->promoter_rep_signature))
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('created_by_name')->label('Erfasst von')->placeholder('–'),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading('Noch keine Checkliste')
            ->emptyStateDescription('Nach der Show: Material und Kleinteile prüfen und von beiden Seiten unterschreiben lassen.')
            ->headerActions([
                CreateAction::make()
                    ->label('Neue Checkliste')
                    ->modalHeading('Neue Checkliste')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->modalWidth('5xl')
                    ->createAnother(false),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('4xl')->iconButton(),
                EditAction::make()->modalWidth('5xl')->iconButton(),
                DeleteAction::make()->iconButton(),
            ]);
    }

    /** Prüfpunkt: Ja/Nein und Anmerkung, gespeichert unter checks.{Schlüssel}. */
    private static function checkRow(string $key, array $item): Grid
    {
        [$name, $question] = $item;

        // Eine Zeile je Prüfpunkt: Frage, Ja/Nein, Anmerkung
        return Grid::make(['default' => 1, 'md' => 12])
            ->statePath($key)
            ->schema([
                ToggleButtons::make('value')
                    ->label($question === null ? $name : "{$name} {$question}")
                    ->inlineLabel()
                    ->options(['yes' => 'Ja', 'no' => 'Nein'])
                    ->colors(['yes' => 'success', 'no' => 'danger'])
                    ->icons(['yes' => Heroicon::OutlinedCheck, 'no' => Heroicon::OutlinedXMark])
                    ->inline()
                    ->grouped()
                    ->columnSpan(['md' => 7]),
                TextInput::make('note')
                    ->label('Anmerkung')
                    ->hiddenLabel()
                    ->placeholder('Anmerkung')
                    ->maxLength(255)
                    ->columnSpan(['md' => 5]),
            ]);
    }

    private function event(): Event
    {
        /** @var Event */
        return $this->getOwnerRecord();
    }
}
