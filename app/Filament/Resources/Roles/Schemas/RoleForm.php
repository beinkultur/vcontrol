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
                            ->state(fn (?Role $record): string => $record?->is_system_admin
                                ? 'Betreiber von VenueControl: alles wie Admin, dazu Module (Verwaltung › System), Rollen & Rechte und die System-Admin-Konten. Die Rechte-Matrix gilt für diese Rolle nicht.'
                                : 'Admin der Halle: Vollzugriff auf alle Bereiche und Kalender – die Rechte-Matrix gilt für diese Rolle nicht. Module, Rollen & Rechte und System-Admins bleiben dem System-Admin.')
                            ->visible(fn (?Role $record): bool => (bool) $record?->is_super || (bool) $record?->is_system_admin)
                            ->columnSpanFull(),
                    ]),
                ...self::areaSections(),
                Section::make('Kalender')
                    ->description('Welche Kalender-Ebenen die Rolle sieht und bearbeiten darf.')
                    ->columns(2)
                    ->hidden(fn (?Role $record): bool => (bool) $record?->is_super || (bool) $record?->is_system_admin)
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
                ->hidden(fn (?Role $record): bool => (bool) $record?->is_super || (bool) $record?->is_system_admin)
                ->schema(array_map(
                    fn (Area $area) => AccessFields::level(
                        'permissions.' . $area->value,
                        $area->label() . ($area->isDisabled() ? ' (Modul abgeschaltet)' : ''),
                    )->helperText($area === Area::AdminRollen ? 'Rollen ansehen; ändern kann sie nur der System-Admin.' : null),
                    Area::inGroup($group),
                ));
        }

        return $sections;
    }
}
