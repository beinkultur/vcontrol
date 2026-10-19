<?php

namespace App\Support;

use App\Mail\CostingMail;
use App\Models\Costing;
use App\Models\CostingItem;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Costings verschicken (Modul Preislisten & Costing): Mail an den Veranstalter
 * mit dem Costing als PDF im Anhang (CostingPdf). Vorbelegt mit den
 * Ansprechpartnern des Veranstalters, in Kopie den Adressen der Venue und dem
 * Absender, Betreff und Text aus Verwaltung › Venue (CostingSettings) mit
 * Platzhaltern wie beim Daysheet. Dazu die Zahlen, wie Formular und PDF sie
 * zeigen.
 */
final class Costings
{
    /**
     * Vorbelegung des Versand-Dialogs.
     *
     * @return array{to: list<string>, bcc: list<string>, subject: string, body: string}
     */
    public static function defaults(Costing $costing, ?User $sender = null): array
    {
        $contacts = $costing->event->promoter?->contacts()->pluck('email')->all() ?? [];

        return [
            'to' => Daysheets::emails($contacts),
            'bcc' => Daysheets::emails([...CostingSettings::bcc(), ...($sender !== null ? [$sender->email] : [])]),
            'subject' => self::fill(CostingSettings::subject(), $costing),
            'body' => self::fill(CostingSettings::text(), $costing),
        ];
    }

    /** Platzhalter des Daysheets ({event}, {datum}, {venue}), dazu {fassung} und {preisliste} */
    public static function fill(string $text, Costing $costing): string
    {
        return strtr(Daysheets::fill($text, $costing->event), [
            '{fassung}' => $costing->label(),
            '{preisliste}' => $costing->priceList?->name ?? '–',
        ]);
    }

    /**
     * Verschicken: eine Mail an „An“, die übrigen im BCC, das PDF im Anhang.
     * Danach gilt die Fassung als verschickt und bleibt, wie sie ist. Scheitert
     * der Versand, ändert sich nichts.
     *
     * @param  list<string>  $to
     * @param  list<string>  $bcc
     */
    public static function send(Costing $costing, array $to, array $bcc, string $subject, string $body, ?User $sender = null): Costing
    {
        $to = Daysheets::emails($to);
        $bcc = array_values(array_diff(Daysheets::emails($bcc), $to));
        if ($to === []) {
            throw new InvalidArgumentException('Ein Costing braucht mindestens einen Empfänger unter „An“.');
        }
        $costing->loadMissing(['event.promoter', 'priceList', 'items']);

        Mail::to($to)->bcc($bcc)->send(new CostingMail(
            subjectLine: self::fill(trim($subject), $costing),
            text: self::fill(str_replace(["\r\n", "\r"], "\n", $body), $costing),
            pdf: CostingPdf::render($costing),
            fileName: $costing->fileName(),
            replyToAddress: $sender?->email,
            replyToName: $sender?->getFilamentName(),
        ));

        $costing->update(['sent_at' => now(), 'sent_to' => $to, 'sent_by_name' => $sender?->getFilamentName()]);

        return $costing;
    }

    /**
     * Summen aus dem Formular (Posten als Arrays, Zahlen als Zeichenketten wie
     * „2.800,00“); ausgeschaltete Posten zählen nicht.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{net: float, vat: float, gross: float, open: int}  open: eingeschaltete Posten nach Aufwand
     */
    public static function totals(array $items, float $vatRate): array
    {
        $net = 0.0;
        $open = 0;
        foreach ($items as $item) {
            if (!is_array($item) || ($item['is_active'] ?? true) === false) {
                continue;
            }
            $amount = CostingItem::calculate($item['price'] ?? null, $item['factor'] ?? null, $item['unit'] ?? null, $item['minimum'] ?? null);
            if ($amount === null) {
                $open++;
            } else {
                $net += $amount;
            }
        }
        $net = round($net, 2);
        $vat = Costing::percentOf($net, $vatRate);

        return ['net' => $net, 'vat' => $vat, 'gross' => round($net + $vat, 2), 'open' => $open];
    }

    /** „2.800,00 €“ */
    public static function money(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' €';
    }

    /** „2.000“, „0,5“, „19“ – ohne überflüssige Nachkommastellen */
    public static function number(float $value): string
    {
        $formatted = number_format($value, 2, ',', '.');

        return str_contains($formatted, ',') ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }
}
