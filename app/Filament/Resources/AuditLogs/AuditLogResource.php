<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Events\EventResource;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Support\AuditPresenter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Änderungsprotokoll wie /audit der PHP-Version (Recht „Audit“): wer hat wann
 * was geändert, mit den Werten vorher und nachher. Nur lesen.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'audit';

    protected static ?string $modelLabel = 'Änderung';

    protected static ?string $pluralModelLabel = 'Audit';

    protected static ?string $navigationLabel = 'Audit';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 90;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i:s'),
                TextEntry::make('user_name')->label('Benutzer')->placeholder('–'),
                TextEntry::make('action')->label('Aktion')->badge()
                    ->formatStateUsing(fn (string $state): string => AuditPresenter::action($state))
                    ->color(fn (string $state): string => AuditPresenter::actionColor($state)),
                TextEntry::make('subject')->label('Bereich')->formatStateUsing(fn (string $state): string => AuditPresenter::subject($state)),
                TextEntry::make('subject_label')->label('Datensatz')->placeholder('–'),
                TextEntry::make('event.title')->label('Event')->placeholder('–'),
                View::make('filament.audit.changes')
                    ->viewData(fn (AuditLog $record): array => ['rows' => AuditPresenter::changes($record), 'action' => $record->action])
                    ->columnSpanFull(),
                TextEntry::make('ip_address')->label('IP-Adresse')->placeholder('–'),
                TextEntry::make('user_agent')->label('Browser')->placeholder('–')->columnSpan(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('event'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('user_name')->label('Benutzer')->placeholder('–')->searchable(),
                TextColumn::make('event.title')
                    ->label('Event')
                    ->placeholder('–')
                    ->description(fn (AuditLog $record): ?string => $record->event?->starts_at?->format('d.m.Y'))
                    ->url(fn (AuditLog $record): ?string => $record->event === null ? null
                        : EventResource::getUrl(EventResource::canEdit($record->event) ? 'edit' : 'view', ['record' => $record->event])),
                TextColumn::make('subject')->label('Bereich')->formatStateUsing(fn (string $state): string => AuditPresenter::subject($state)),
                TextColumn::make('subject_label')->label('Datensatz')->placeholder('–')->limit(40)->searchable(),
                TextColumn::make('action')->label('Aktion')->badge()
                    ->formatStateUsing(fn (string $state): string => AuditPresenter::action($state))
                    ->color(fn (string $state): string => AuditPresenter::actionColor($state)),
                TextColumn::make('summary')
                    ->label('Änderung')
                    ->state(fn (AuditLog $record): string => AuditPresenter::summary($record))
                    ->wrap()
                    ->limit(180),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Event')
                    ->relationship('event', 'title', fn (Builder $query): Builder => $query->orderByDesc('starts_at'))
                    ->getOptionLabelFromRecordUsing(fn (Event $event): string => ($event->starts_at?->format('d.m.Y') ?? '–') . ' · ' . $event->title)
                    ->searchable(),
                SelectFilter::make('subject')
                    ->label('Bereich')
                    ->options(AuditPresenter::SUBJECTS),
                SelectFilter::make('action')
                    ->label('Aktion')
                    ->options(AuditPresenter::ACTIONS),
                SelectFilter::make('user_id')
                    ->label('Benutzer')
                    ->options(fn (): array => User::query()->whereIn('id', AuditLog::query()->select('user_id')->distinct())
                        ->orderBy('last_name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->getFilamentName()])->all())
                    ->searchable(),
                Filter::make('period')
                    ->label('Zeitraum')
                    ->schema([
                        DatePicker::make('from')->label('Von'),
                        DatePicker::make('until')->label('Bis'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('4xl')->iconButton(),
            ])
            ->paginated([50, 100, 250])
            ->emptyStateHeading('Noch keine Änderungen protokolliert');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
