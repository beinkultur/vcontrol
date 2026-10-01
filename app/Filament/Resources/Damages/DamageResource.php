<?php

namespace App\Filament\Resources\Damages;

use App\Access\Area;
use App\Filament\Resources\Damages\Pages\ManageDamages;
use App\Filament\Support\DamageFields;
use App\Models\Damage;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Alle Schäden wie /protokolle/schaeden der PHP-Version (Recht „Protokolle“),
 * auch allgemeine ohne Event – die werden hier gemeldet.
 */
class DamageResource extends Resource
{
    protected static ?string $model = Damage::class;

    protected static ?string $slug = 'schaeden';

    protected static ?string $modelLabel = 'Schaden';

    protected static ?string $pluralModelLabel = 'Schäden';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Protokolle';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Protokolle);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(DamageFields::form(withEvent: true));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components(DamageFields::infolist(withEvent: true));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('event'))
            ->defaultSort('recorded_at', 'desc')
            ->columns(DamageFields::columns(withEvent: true))
            ->filters([
                TernaryFilter::make('is_fixed')
                    ->label('Behoben')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('is_fixed', true),
                        false: fn (Builder $query): Builder => $query->where('is_fixed', false),
                    ),
                Filter::make('general')
                    ->label('Nur allgemeine (ohne Event)')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('event_id')),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('3xl')->iconButton(),
                EditAction::make()->modalWidth('3xl')->iconButton(),
                DamageFields::fixAction(),
            ])
            ->paginated([50, 100, 'all'])
            ->emptyStateHeading('Keine Schäden');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDamages::route('/'),
        ];
    }
}
