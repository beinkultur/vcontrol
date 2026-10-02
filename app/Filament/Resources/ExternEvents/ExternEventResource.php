<?php

namespace App\Filament\Resources\ExternEvents;

use App\Access\Area;
use App\Filament\Resources\ExternEvents\Pages\ListExternEvents;
use App\Filament\Resources\ExternEvents\Pages\ViewExternEvent;
use App\Models\ExternEvent;
use App\Models\User;
use App\Support\EventDisplay;
use App\Support\ExternSheet;
use App\Support\Involvement;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Extern-Bereich „Meine Events“ für Freelancer und Gewerke mit eigenem Konto,
 * wie /extern/events der PHP-Version: nur Events, an denen das Konto beteiligt
 * ist (App\Support\Involvement), nur lesend und ohne Sensibles
 * (App\Support\ExternSheet). Rechte: App\Policies\ExternEventPolicy.
 */
class ExternEventResource extends Resource
{
    protected static ?string $model = ExternEvent::class;

    protected static ?string $slug = 'extern/events';

    protected static ?string $modelLabel = 'Event';

    protected static ?string $pluralModelLabel = 'Meine Events';

    protected static ?string $navigationLabel = 'Meine Events';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isGloballySearchable = false;

    /**
     * Zugang zu den Seiten: Externe mit „Events (extern)“ und – für die Ansicht
     * eines Events („Ansicht Extern“) – wer intern Events lesen darf. Die Liste
     * verlangt „Events (extern)“ selbst (ListExternEvents::authorizeAccess).
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return static::canViewAny() || ($user instanceof User && $user->access()->can(Area::Events));
    }

    /** Im Menü nur für Externe – intern gibt es „Events“, die Ansicht für Externe kommt von dort. */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user instanceof User && !$user->access()->canUseApp() && static::canViewAny();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('extern.sheet')
                    ->viewData(fn (ExternEvent $record): array => [
                        'sheet' => ExternSheet::make($record, fn ($file): string => $file->downloadUrl()),
                        'showHead' => false,
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::involved($query)->whereNotNull('starts_at')->with(['promoter', 'schedule']))
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Datum')
                    ->formatStateUsing(fn (CarbonInterface $state): string => EventDisplay::weekday($state) . ', ' . $state->format('d.m.Y'))
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Veranstaltung')
                    ->weight(FontWeight::SemiBold)
                    ->searchable(),
                TextColumn::make('promoter.name')
                    ->label('Veranstalter')
                    ->placeholder('–'),
                TextColumn::make('schedule.start_time')
                    ->label('Beginn')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? substr($state, 0, 5) . ' Uhr' : '–')
                    ->placeholder('–'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => EventDisplay::statusLabel($state))
                    ->color(fn (?string $state): string => EventDisplay::statusKind($state) === 'cancelled' ? 'danger' : 'gray'),
            ])
            ->defaultSort('starts_at')
            ->filters([
                SelectFilter::make('time')
                    ->label('Zeitraum')
                    ->options(['future' => 'Zukünftige', 'past' => 'Vergangene', 'all' => 'Alle'])
                    ->default('future')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'future') {
                        'past' => $query->whereDate('starts_at', '<', today()),
                        'all' => $query,
                        default => $query->whereDate('starts_at', '>=', today()),
                    }),
            ])
            ->recordUrl(fn (ExternEvent $record): string => static::getUrl('view', ['record' => $record]))
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Keine Events')
            ->emptyStateDescription('Hier erscheinen die Events, bei denen dein Konto im Personal oder als Gewerk eingetragen ist.');
    }

    /** Nur die Events, an denen das angemeldete Konto beteiligt ist – auch für Admins. */
    private static function involved(Builder $query): Builder
    {
        $user = Auth::user();

        return $user instanceof User ? Involvement::scope($query, $user) : $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExternEvents::route('/'),
            'view' => ViewExternEvent::route('/{record}'),
        ];
    }
}
