<?php

namespace App\Support;

use App\Models\Event;

/**
 * Prüfungen der Buchhaltungsliste (21.10.2026). Rechnungen stehen im
 * FIBU-Status als Paare „… gestellt“ (verschickt) und „… gezahlt“, etwa
 * „1. Rate / Grundmiete gestellt“ und „… gezahlt“; wer nur „gezahlt“ anhakt,
 * hat sie auch verschickt. Der Vertragsstatus ist eine Komma-Liste; zurück ist
 * er mit „… zurück“ oder einem Rahmenvertrag. Gesucht wird nach den Wörtern,
 * nicht nach dem ganzen Wert – so passen auch eigene Feldoptionen wie
 * „✅ Vertrag zurück“.
 *
 * Gefiltert wird in PHP über ids(): Die Regeln (auch Event::hasFinanceAlert und
 * IncomingInvoices) gibt es nur einmal, und Venues haben einige Hundert Events.
 */
final class AccountingChecks
{
    public const CONTRACT_OPEN = 'vertrag';

    public const INVOICE_OPEN = 'rechnung';

    public const FINANCE_ALERT = 'warnung';

    public const INCOMING_MISSING = 'eingang';

    /** Für den Filter „Prüfen“ der Buchhaltungsliste */
    public const LABELS = [
        self::CONTRACT_OPEN => 'Vertrag versendet, nicht zurück',
        self::INVOICE_OPEN => 'Rechnung versendet, nicht gezahlt',
        self::FINANCE_ALERT => 'Finanz-Warnung (!)',
        self::INCOMING_MISSING => 'Event abgeschlossen, Eingangsrechnungen fehlen',
    ];

    /**
     * Die Rechnungen aus dem FIBU-Status, z. B. „2. Rate“ => verschickt, bezahlt.
     *
     * @return array<string, array{sent: bool, paid: bool}>
     */
    public static function invoices(Event $event): array
    {
        $invoices = [];
        foreach ((array) ($event->finance?->accounting_status ?? []) as $value) {
            if (preg_match('/^(.*?)\s+(gestellt|gezahlt|bezahlt)$/iu', trim((string) $value), $m) !== 1) {
                continue;
            }
            $key = mb_strtolower(trim($m[1]));
            $invoices[$key] ??= ['sent' => false, 'paid' => false];
            $invoices[$key]['sent'] = true;
            if (mb_strtolower($m[2]) !== 'gestellt') {
                $invoices[$key]['paid'] = true;
            }
        }

        return $invoices;
    }

    /**
     * FIBU-Status für die Liste, je Rechnung ein Wert: „… gezahlt“, wenn bezahlt,
     * sonst „… gestellt“; was kein Paar ist, bleibt, wie es ist. Reihenfolge wie
     * angehakt.
     *
     * @return list<string>
     */
    public static function condensedStatus(Event $event): array
    {
        $values = array_values(array_filter(array_map(fn (mixed $v): string => trim((string) $v), (array) ($event->finance?->accounting_status ?? [])), 'filled'));
        $paid = [];
        foreach ($values as $value) {
            if (preg_match('/^(.*?)\s+(gezahlt|bezahlt)$/iu', $value, $m) === 1) {
                $paid[mb_strtolower(trim($m[1]))] = true;
            }
        }

        $result = [];
        foreach ($values as $value) {
            if (preg_match('/^(.*?)\s+gestellt$/iu', $value, $m) === 1 && isset($paid[mb_strtolower(trim($m[1]))])) {
                continue; // bezahlt – das „gezahlt“ steht schon da
            }
            $result[] = $value;
        }

        return $result;
    }

    /** Grüner Haken: mindestens eine Rechnung verschickt, und alle verschickten sind bezahlt. */
    public static function allPaid(Event $event): bool
    {
        $invoices = self::invoices($event);

        return $invoices !== [] && !in_array(false, array_column($invoices, 'paid'), true);
    }

    public static function invoiceOpen(Event $event): bool
    {
        return in_array(false, array_column(self::invoices($event), 'paid'), true);
    }

    public static function contractOpen(Event $event): bool
    {
        $values = array_map(fn (string $v): string => mb_strtolower(trim($v)), explode(',', (string) $event->finance?->contract_status));
        $has = fn (string $word): bool => array_filter($values, fn (string $v): bool => str_contains($v, $word)) !== [];

        return ($has('versendet') || $has('verschickt')) && !$has('zurück') && !$has('rahmenvertrag');
    }

    /** Das rote Ausrufezeichen der Listen – nur, solange die Abrechnung offen ist */
    public static function financeAlert(Event $event): bool
    {
        return $event->hasFinanceAlert() && !$event->finance?->accounting_closed;
    }

    public static function incomingMissing(Event $event): bool
    {
        return $event->closed && !IncomingInvoices::settled($event);
    }

    public static function matches(Event $event, string $check): bool
    {
        return match ($check) {
            self::CONTRACT_OPEN => self::contractOpen($event),
            self::INVOICE_OPEN => self::invoiceOpen($event),
            self::FINANCE_ALERT => self::financeAlert($event),
            self::INCOMING_MISSING => self::incomingMissing($event),
            'paid' => self::allPaid($event),
            default => false,
        };
    }

    /**
     * IDs aller Events, auf die die Prüfung zutrifft ('paid': grüner Haken).
     *
     * @return list<int>
     */
    public static function ids(string $check): array
    {
        return Event::query()
            ->with(['finance', 'operation', 'incomingInvoices'])
            ->get()
            ->filter(fn (Event $event): bool => self::matches($event, $check))
            ->map(fn (Event $event): int => (int) $event->getKey())
            ->values()
            ->all();
    }
}
