<?php

namespace App\Filament\Resources\FieldOptions;

use App\Enums\OptionField;
use App\Filament\Resources\FieldOptions\Pages\ManageFieldOptions;
use App\Models\FieldOption;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/** Auswahlwerte der Event-Felder, je Feld ein Tab. */
class FieldOptionResource extends Resource
{
    protected static ?string $model = FieldOption::class;

    protected static ?string $slug = 'feldoptionen';

    protected static ?string $modelLabel = 'Auswahlwert';

    protected static ?string $pluralModelLabel = 'Feldoptionen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'value';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('field_key')
                    ->label('Feld')
                    ->options(OptionField::class)
                    ->required()
                    ->live()
                    ->native(false),
                Select::make('parent_value')
                    ->label('Gehört zu VA-Kategorie 1')
                    ->options(fn (): array => FieldOption::choices(OptionField::VaType1))
                    ->visible(fn (Get $get): bool => self::fieldOf($get('field_key'))?->parent() !== null)
                    ->required(fn (Get $get): bool => self::fieldOf($get('field_key'))?->parent() !== null)
                    ->native(false),
                TextInput::make('value')
                    ->label('Wert')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('field_key', self::fieldOf($get('field_key'))?->value)
                            ->where('parent_value', $get('parent_value') ?: null),
                    ),
                Toggle::make('is_active')
                    ->label('Aktiv')
                    ->helperText('Inaktive Werte bleiben an bestehenden Events stehen, sind aber nicht mehr wählbar.')
                    ->default(true),
            ]);
    }

    public static function fieldOf(mixed $state): ?OptionField
    {
        return $state instanceof OptionField ? $state : OptionField::tryFrom((string) $state);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('value')
            ->columns([
                TextColumn::make('value')
                    ->label('Wert')
                    ->searchable(),
                TextColumn::make('parent_value')
                    ->label('Gehört zu')
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) === OptionField::VaType2->value),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFieldOptions::route('/'),
        ];
    }
}
