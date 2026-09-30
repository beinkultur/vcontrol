<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Access\Area;
use App\Filament\Support\AccessFields;
use App\Models\Role;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Rolle')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('slug')
                            ->label('Kennung')
                            ->helperText('Technischer Name, nur Kleinbuchstaben, Ziffern und _. Bei Systemrollen fest.')
                            ->required()
                            ->maxLength(50)
                            ->regex('/^[a-z0-9_]+$/')
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Role $record): bool => (bool) $record?->is_system),
                        TextInput::make('description')
                            ->label('Beschreibung')
                            ->maxLength(500)
                            ->columnSpanFull(),
                        TextInput::make('sort_order')
                            ->label('Reihenfolge')
                            ->numeric()
                            ->default(100),
                        TextEntry::make('super_hint')
                            ->hiddenLabel()
                            ->state('Vollzugriff auf alle Bereiche und Kalender – die Rechte-Matrix gilt für diese Rolle nicht.')
                            ->visible(fn (?Role $record): bool => (bool) $record?->is_super)
                            ->columnSpanFull(),
                    ]),
                ...self::areaSections(),
                Section::make('Kalender')
                    ->description('Welche Kalender-Ebenen die Rolle sieht und bearbeiten darf.')
                    ->columns(2)
                    ->hidden(fn (?Role $record): bool => (bool) $record?->is_super)
                    ->schema(fn (): array => AccessFields::calendars('calendar_permissions')),
            ]);
    }

    /** @return list<Section> */
    private static function areaSections(): array
    {
        $sections = [];
        foreach (Area::groups() as $group) {
            $sections[] = Section::make('Rechte: ' . $group)
                ->columns(2)
                ->hidden(fn (?Role $record): bool => (bool) $record?->is_super)
                ->schema(array_map(
                    fn (Area $area) => AccessFields::level(
                        'permissions.' . $area->value,
                        $area->label() . ($area->isDisabled() ? ' (Modul abgeschaltet)' : ''),
                    ),
                    Area::inGroup($group),
                ));
        }

        return $sections;
    }
}
