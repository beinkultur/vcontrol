<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Support\DamageFields;
use App\Models\Damage;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Durchführung › Schäden wie in der PHP-Version; neue Schäden gehen per Mail an den Hausmeister. */
class DamagesRelationManager extends RelationManager
{
    protected static string $relationship = 'damages';

    protected static ?string $title = 'Schäden';

    protected static ?string $modelLabel = 'Schaden';

    protected static ?string $pluralModelLabel = 'Schäden';

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
        return $schema->columns(2)->components(DamageFields::form(withEvent: false));
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components(DamageFields::infolist(withEvent: false));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('recorded_at', 'desc')
            ->columns(DamageFields::columns(withEvent: false))
            ->filters([
                TernaryFilter::make('is_fixed')
                    ->label('Behoben')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('is_fixed', true),
                        false: fn (Builder $query): Builder => $query->where('is_fixed', false),
                    ),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading('Keine Schäden')
            ->headerActions([
                CreateAction::make()
                    ->label('Schaden melden')
                    ->modalHeading('Schaden melden')
                    ->modalSubmitActionLabel('Melden')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->modalWidth('3xl')
                    ->createAnother(false)
                    ->using(fn (array $data): Damage => DamageFields::create($data, $this->getOwnerRecord()->getKey())),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('3xl')->iconButton(),
                EditAction::make()->modalWidth('3xl')->iconButton(),
                DamageFields::fixAction(),
            ]);
    }
}
