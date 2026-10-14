<?php

namespace App\Filament\Resources\Inquiries;

use App\Enums\OptionField;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Inquiries\Pages\CreateInquiry;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\Inquiries\Pages\ViewInquiry;
use App\Filament\Support\OptionChoices;
use App\Models\Inquiry;
use App\Models\InquiryDate;
use App\Support\Inquiries;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Anfragen (Optionen) wie /anfragen der PHP-Version: Veranstalter fragen einen
 * oder mehrere Termine an; je Tag zählt der Eingang (1. Option, 2. Option …,
 * App\Support\Inquiries). Bestätigen macht aus einem Termin ein Event,
 * Zurückziehen archiviert. Im Kalender stehen offene Anfragen auf der Ebene
 * „Anfragen“. Rechte: InquiryPolicy (Bereich „Anfragen“).
 */
class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static ?string $slug = 'anfragen';

    protected static ?string $modelLabel = 'Anfrage';

    protected static ?string $pluralModelLabel = 'Anfragen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Section::make('Anfrage')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Titel')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('promoter_id')
                            ->label('Veranstalter')
                            ->relationship('promoter', 'name', fn ($query) => $query->where('is_archived', false)->orderBy('name'))
                            ->searchable()
                            ->preload(),
                        DatePicker::make('option_deadline')
                            ->label('Optionsfrist')
                            ->helperText('Bis wann der Veranstalter sich entscheidet. Vorgabe: in ' . Inquiry::DEADLINE_DAYS . ' Tagen.')
                            ->default(fn (): string => Inquiries::defaultDeadline()->toDateString()),
                        Textarea::make('comment')
                            ->label('Kommentar')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Wunschtermine')
                    ->description('Je Tag zählt der Eingang: Die erste offene Anfrage ist die 1. Option.')
                    ->schema([
                        Repeater::make('dates')
                            ->hiddenLabel()
                            ->relationship()
                            ->table([
                                TableColumn::make('Termin')->markAsRequired(),
                            ])
                            ->schema([
                                DatePicker::make('option_date')
                                    ->label('Termin')
                                    ->required()
                                    ->distinct(),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->orderColumn(false)
                            ->addActionLabel('Termin hinzufügen'),
                    ]),
                Section::make('Eckdaten fürs Event')
                    ->description('Gehen beim Bestätigen ins neue Event.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('event_type1')
                            ->label('Kategorie 1')
                            ->options(fn (?Inquiry $record): array => OptionChoices::for(OptionField::VaType1, $record?->event_type1))
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('event_type2', null))
                            ->native(false),
                        Select::make('event_type2')
                            ->label('Kategorie 2')
                            ->options(fn (Get $get, ?Inquiry $record): array => OptionChoices::for(OptionField::VaType2, $record?->event_type2, $get('event_type1')))
                            ->native(false),
                        CheckboxList::make('areas')
                            ->label('Bereiche')
                            ->options(fn (?Inquiry $record): array => OptionChoices::for(OptionField::Areas, $record?->areas))
                            ->columns(2),
                        CheckboxList::make('seating')
                            ->label('Bestuhlung')
                            ->options(fn (?Inquiry $record): array => OptionChoices::for(OptionField::Seating, $record?->seating))
                            ->columns(2),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Section::make('Anfrage')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('promoter.name')->label('Veranstalter')->placeholder('–'),
                        TextEntry::make('status')->label('Status')->badge()
                            ->formatStateUsing(fn (string $state): string => Inquiry::statusLabel($state))
                            ->color(fn (string $state): string => match ($state) {
                                Inquiry::CONFIRMED => 'success',
                                Inquiry::WITHDRAWN => 'gray',
                                default => 'warning',
                            }),
                        TextEntry::make('option_deadline')->label('Optionsfrist')->date('d.m.Y')->placeholder('–')
                            ->color(fn (Inquiry $record): ?string => $record->isOpen() && $record->option_deadline?->isPast() ? 'danger' : null),
                        TextEntry::make('created_at')->label('Eingang')
                            ->formatStateUsing(fn (Inquiry $record): string => $record->created_at->format('d.m.Y') . (filled($record->created_by_name) ? ' · ' . $record->created_by_name : '')),
                        TextEntry::make('event.title')->label('Event')->placeholder('–')
                            ->url(fn (Inquiry $record): ?string => $record->event !== null ? EventResource::getUrl('edit', ['record' => $record->event]) : null),
                        TextEntry::make('comment')->label('Kommentar')->placeholder('–')->columnSpanFull(),
                    ]),
                Section::make('Wunschtermine')
                    ->schema([
                        View::make('filament.inquiries.dates')
                            ->viewData(fn (Inquiry $record): array => ['inquiry' => $record]),
                    ]),
                Section::make('Eckdaten fürs Event')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('event_type1')->label('Kategorie 1')->placeholder('–'),
                        TextEntry::make('event_type2')->label('Kategorie 2')->placeholder('–'),
                        TextEntry::make('areas')->label('Bereiche')->placeholder('–')->badge(),
                        TextEntry::make('seating')->label('Bestuhlung')->placeholder('–')->badge(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_date')
                    ->label('Erster Termin')
                    ->state(fn (Inquiry $record): ?string => $record->dates->first()?->option_date?->format('d.m.Y'))
                    ->placeholder('–'),
                TextColumn::make('title')
                    ->label('Anfrage')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('promoter.short_name')
                    ->label('Veranstalter')
                    ->state(fn (Inquiry $record): ?string => $record->promoter?->short_name ?: $record->promoter?->name)
                    ->tooltip(fn (Inquiry $record): ?string => $record->promoter?->name)
                    ->visibleFrom('md'),
                ViewColumn::make('dates')
                    ->label('Termine')
                    ->view('filament.inquiries.dates-column'),
                TextColumn::make('option_deadline')
                    ->label('Optionsfrist')
                    ->date('d.m.Y')
                    ->color(fn (Inquiry $record): ?string => $record->isOpen() && $record->option_deadline?->isPast() ? 'danger' : null)
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Inquiry::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        Inquiry::CONFIRMED => 'success',
                        Inquiry::WITHDRAWN => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')
                    ->label('Eingang')
                    ->date('d.m.Y')
                    ->visibleFrom('lg'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['promoter', 'dates'])
                ->orderBy(InquiryDate::query()->select('option_date')->whereColumn('inquiry_id', 'inquiries.id')->orderBy('option_date')->limit(1))
                ->orderByDesc('created_at'))
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([Inquiry::OPEN => 'Offen', Inquiry::CONFIRMED => 'Bestätigt', Inquiry::WITHDRAWN => 'Zurückgezogen'])
                    ->default(Inquiry::OPEN),
                SelectFilter::make('time')
                    ->label('Zeitraum')
                    ->options(['future' => 'Zukünftige', 'past' => 'Vergangene'])
                    ->default('future')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'future' => $query->whereHas('dates', fn (Builder $dates): Builder => $dates->whereDate('option_date', '>=', today())),
                        'past' => $query->whereDoesntHave('dates', fn (Builder $dates): Builder => $dates->whereDate('option_date', '>=', today())),
                        default => $query,
                    }),
                SelectFilter::make('promoter_id')
                    ->label('Veranstalter')
                    ->relationship('promoter', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordUrl(fn (Inquiry $record): string => static::getUrl('view', ['record' => $record]))
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInquiries::route('/'),
            'create' => CreateInquiry::route('/create'),
            'view' => ViewInquiry::route('/{record}'),
            'edit' => EditInquiry::route('/{record}/edit'),
        ];
    }
}
