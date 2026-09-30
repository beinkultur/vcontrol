<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventIncomingInvoice;

/**
 * Erwartete Eingangsrechnungen eines Events. Ein gespeicherter Eintrag gewinnt;
 * ohne Eintrag sind zwei Positionen automatisch aktiv: Mobiliar/Stühle bei der
 * Bestuhlung „bestuhlt“ (nicht „teilbestuhlt“) und Cobra – Haus-Delay bei Haus-Delay.
 * Die SQL-Fassung derselben Regel steht in Event::scopeIncomingInvoicesSettled().
 */
final class IncomingInvoices
{
    public const SEATING_TRIGGER = 'bestuhlt';

    /** @return list<array{key: string, label: string, active: bool, received: bool, auto: bool}> */
    public static function slots(Event $event): array
    {
        $stored = $event->incomingInvoices->keyBy('invoice_key');
        $auto = [
            'mobiliar_stuehle' => in_array(self::SEATING_TRIGGER, $event->seating ?? [], true),
            'cobra_hausdelay' => (bool) $event->operation?->house_delay,
        ];

        $slots = [];
        foreach (EventIncomingInvoice::SLOTS as $key => $label) {
            $row = $stored->get($key);
            $slots[] = [
                'key' => $key,
                'label' => $label,
                'active' => $row ? $row->is_active : ($auto[$key] ?? false),
                'received' => $row ? $row->is_received : false,
                'auto' => $auto[$key] ?? false,
            ];
        }

        return $slots;
    }

    /** „eingegangen/erwartet“, z. B. „1/3“; leer, wenn nichts erwartet wird. */
    public static function summary(Event $event): ?string
    {
        $active = array_filter(self::slots($event), fn (array $s): bool => $s['active']);
        if ($active === []) {
            return null;
        }

        return count(array_filter($active, fn (array $s): bool => $s['received'])) . '/' . count($active);
    }

    public static function settled(Event $event): bool
    {
        foreach (self::slots($event) as $slot) {
            if ($slot['active'] && !$slot['received']) {
                return false;
            }
        }

        return true;
    }
}
