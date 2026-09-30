<?php

namespace App\Filament\Support;

use App\Enums\Responsible;
use App\Enums\ServiceCode;
use App\Models\Event;
use App\Models\EventService;
use App\Models\Trade;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Leistungen am Event: je Leistung wer sie stellt, Gewerk, freier Anbieter,
 * Notiz. Gespeichert wird nur, was befüllt ist; eine leere Leistung heißt
 * „nicht festgelegt“ und wird entfernt (außer sie trägt einen Aktiv-Schalter).
 */
final class ServiceFields
{
    /** @return list<Fieldset> */
    public static function all(): array
    {
        return array_map(fn (ServiceCode $service): Fieldset => self::row($service), ServiceCode::cases());
    }

    private static function row(ServiceCode $service): Fieldset
    {
        $name = fn (string $field): string => "service_{$service->value}_{$field}";
        $current = fn (?Event $record): ?EventService => $record?->services->firstWhere('service', $service);

        return Fieldset::make($service->getLabel())
            ->columns(4)
            ->schema([
                ToggleButtons::make($name('responsible'))
                    ->hiddenLabel()
                    ->options(Responsible::class)
                    ->grouped()
                    ->afterStateHydrated(fn (ToggleButtons $component, ?Event $record) => $component->state($current($record)?->responsible?->value))
                    ->dehydrated(false)
                    ->saveRelationshipsUsing(fn (Event $record, Get $get) => self::save($record, $service, $get)),
                Select::make($name('trade'))
                    ->hiddenLabel()
                    ->placeholder('Gewerk')
                    ->options(fn (?Event $record): array => self::tradeOptions($service, $current($record)?->trade_id))
                    ->searchable()
                    ->afterStateHydrated(fn (Select $component, ?Event $record) => $component->state($current($record)?->trade_id))
                    ->dehydrated(false),
                TextInput::make($name('provider'))
                    ->hiddenLabel()
                    ->placeholder('Anbieter ohne Gewerk')
                    ->maxLength(255)
                    ->afterStateHydrated(fn (TextInput $component, ?Event $record) => $component->state($current($record)?->provider_label))
                    ->dehydrated(false),
                TextInput::make($name('note'))
                    ->hiddenLabel()
                    ->placeholder('Notiz')
                    ->afterStateHydrated(fn (TextInput $component, ?Event $record) => $component->state($current($record)?->note))
                    ->dehydrated(false),
            ]);
    }

    /**
     * Gewerke mit dem passenden Leistungsbereich, plus das schon gewählte.
     *
     * @return array<int, string>
     */
    private static function tradeOptions(ServiceCode $service, ?int $currentId): array
    {
        $query = Trade::query()->where('is_archived', false)->orderBy('name');
        if ($service->tradeCategory() !== null) {
            $query->whereJsonContains('categories', $service->tradeCategory());
        }
        $options = $query->get()->mapWithKeys(fn (Trade $t): array => [$t->id => $t->displayName()])->all();

        if ($currentId !== null && !isset($options[$currentId])) {
            $trade = Trade::query()->find($currentId);
            if ($trade !== null) {
                $options[$trade->id] = $trade->displayName();
            }
        }

        return $options;
    }

    private static function save(Event $event, ServiceCode $service, Get $get): void
    {
        // Bei Optionen aus einer Enum-Klasse liefert Filament den Zustand als Enum
        $field = function (string $name) use ($get, $service): string {
            $value = $get("service_{$service->value}_{$name}");

            return trim((string) ($value instanceof \BackedEnum ? $value->value : $value));
        };
        $values = [
            'responsible' => Responsible::tryFrom($field('responsible'))?->value,
            'trade_id' => (int) $field('trade') ?: null,
            'provider_label' => $field('provider') ?: null,
            'note' => $field('note') ?: null,
        ];

        $existing = $event->services()->where('service', $service->value)->first();
        if (array_filter($values) === []) {
            if ($existing !== null && $existing->is_active === null) {
                $existing->delete();
            } elseif ($existing !== null) {
                $existing->update($values); // Aktiv-Schalter bleibt erhalten
            }

            return;
        }

        $event->services()->updateOrCreate(['service' => $service->value], $values);
    }
}
