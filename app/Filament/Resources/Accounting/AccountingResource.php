<?php

namespace App\Filament\Resources\Accounting;

use App\Access\Area;
use App\Filament\Resources\Accounting\Pages\ListAccounting;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Support\IncomingInvoices;
use App\Support\StagePodests;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Buchhaltungsliste: dieselben Events, gesehen von der Buchhaltung. Zugang über
 * den Bereich Buchhaltung, nicht über Events – deshalb eigene Rechteprüfung
 * statt der EventPolicy. Bearbeitet wird im Event-Workspace.
 */
class AccountingResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $slug = 'buchhaltung';

    protected static ?string $modelLabel = 'Event';

    protected static ?string $pluralModelLabel = 'Buchhaltung';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Buchhaltung);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Datum')
                    ->date('D, d.m.Y')
                    ->icon(fn (Event $record): ?Heroicon => $record->hasFinanceAlert() && !$record->finance?->accounting_closed ? Heroicon::ExclamationTriangle : null)
                    ->iconColor('danger')
                    ->tooltip(fn (Event $record): ?string => $record->hasFinanceAlert() && !$record->finance?->accounting_closed
                        ? 'Finanz-Warnung: Event in höchstens 14 Tagen, Vertrag nicht zurück oder 2. Rate nicht gezahlt'
                        : null)
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Titel')
                    ->description(fn (Event $record): ?string => $record->promoter?->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('va_id')
                    ->label('VA-ID')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),
                TextColumn::make('finance.contract_status')
                    ->label('Vertrag')
                    ->toggleable(),
                TextColumn::make('finance.accounting_status')
                    ->label('FIBU-Status')
                    ->badge(),
                TextColumn::make('finance.rent')
                    ->label('Miete')
                    ->money('EUR', locale: 'de')
                    ->alignEnd(),
                TextColumn::make('operation.bus_power')
                    ->label('Bus-Strom')
                    ->tooltip('Anschlüsse, einzeln abzurechnen')
                    ->color(fn (?int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->alignEnd()
                    ->toggleable(),
                // Wie in der PHP-Version aus der gespeicherten Summe; leer zählt 0.
                TextColumn::make('extra_podests')
                    ->label('Zus. Podeste')
                    ->state(fn (Event $record): int => StagePodests::billableExtra($record->stage?->podest_total))
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->tooltip(fn (): string => 'zusätzlich zu berechnen, ' . StagePodests::includedInRent() . ' sind im Mietpreis enthalten')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('finance.invoice_numbers')
                    ->label('Rechnungen')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                IconColumn::make('finance.accounting_closed')
                    ->label('Abgeschl.')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('incoming_invoices')
                    ->label('Eing.-Rg.')
                    ->state(fn (Event $record): ?string => IncomingInvoices::summary($record))
                    ->color(fn (Event $record): string => IncomingInvoices::settled($record) ? 'success' : 'warning')
                    ->tooltip('eingegangen / erwartet')
                    ->alignCenter(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['promoter', 'finance', 'operation', 'incomingInvoices', 'stage']))
            ->filters([
                SelectFilter::make('year')
                    ->label('Jahr')
                    ->options(fn (): array => Event::query()->whereNotNull('starts_at')->pluck('starts_at')
                        ->map(fn ($date): string => (string) $date->year)->unique()->sortDesc()
                        ->mapWithKeys(fn (string $year): array => [$year => $year])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereYear('starts_at', (int) $data['value'])
                        : $query),
                SelectFilter::make('promoter')
                    ->label('Veranstalter')
                    ->relationship('promoter', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordUrl(fn (Event $record): ?string => match (true) {
                EventResource::canEdit($record) => EventResource::getUrl('edit', ['record' => $record]),
                EventResource::canView($record) => EventResource::getUrl('view', ['record' => $record]),
                default => null,
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounting::route('/'),
        ];
    }
}
