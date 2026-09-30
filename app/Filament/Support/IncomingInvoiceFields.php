<?php

namespace App\Filament\Support;

use App\Models\Event;
use App\Models\EventIncomingInvoice;
use App\Support\IncomingInvoices;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Je Eingangsrechnung zwei Schalter: „erwartet“ und „Rechnung da“. Der zweite
 * erscheint sofort, wenn der erste an ist. Gespeichert wird nur, was vom
 * automatischen Stand abweicht – unberührte Positionen folgen weiter der
 * Automatik (Mobiliar bei „bestuhlt“, Cobra bei Haus-Delay).
 */
final class IncomingInvoiceFields
{
    /** @return list<Fieldset> */
    public static function all(): array
    {
        $fields = [];
        foreach (EventIncomingInvoice::SLOTS as $key => $label) {
            $fields[] = Fieldset::make($label)
                ->columns(2)
                ->schema([
                    Toggle::make("invoice_{$key}_active")
                        ->label('erwartet')
                        ->live()
                        ->afterStateHydrated(fn (Toggle $component, ?Event $record) => $component->state(self::slot($record, $key)['active'] ?? false))
                        ->dehydrated(false),
                    Toggle::make("invoice_{$key}_received")
                        ->label('Rechnung da')
                        ->visible(fn (Get $get): bool => (bool) $get("invoice_{$key}_active"))
                        ->afterStateHydrated(fn (Toggle $component, ?Event $record) => $component->state(self::slot($record, $key)['received'] ?? false))
                        ->dehydrated(false)
                        ->saveRelationshipsWhenHidden()
                        ->saveRelationshipsUsing(fn (Event $record, Get $get, ?bool $state) => self::save(
                            $record,
                            $key,
                            (bool) $get("invoice_{$key}_active"),
                            (bool) $state,
                        )),
                ]);
        }

        return $fields;
    }

    /** @return array{key: string, label: string, active: bool, received: bool, auto: bool}|null */
    private static function slot(?Event $event, string $key): ?array
    {
        return $event ? collect(IncomingInvoices::slots($event))->firstWhere('key', $key) : null;
    }

    private static function save(Event $event, string $key, bool $active, bool $received): void
    {
        $received = $active && $received; // nicht erwartet heißt auch nicht eingegangen
        $current = self::slot($event, $key);
        $stored = $event->incomingInvoices()->where('invoice_key', $key)->exists();
        if (!$stored && $current['active'] === $active && $current['received'] === $received) {
            return; // unverändert – die Automatik bleibt zuständig
        }

        $values = ['is_active' => $active, 'is_received' => $received, 'updated_by' => Auth::id(), 'updated_at' => now()];
        $query = DB::table('event_incoming_invoices')->where(['event_id' => $event->id, 'invoice_key' => $key]);
        $stored
            ? $query->update($values)
            : DB::table('event_incoming_invoices')->insert($values + ['event_id' => $event->id, 'invoice_key' => $key, 'created_at' => now()]);
    }
}
