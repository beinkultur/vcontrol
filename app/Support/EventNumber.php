<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Promoter;

/**
 * Laufende Nummer (VA-NR) und VA-ID eines Events – wie in der PHP-Version:
 * VA-ID = Kundennummer des Veranstalters (5-stellig) + laufende Nummer (5-stellig).
 */
final class EventNumber
{
    public const NR_LENGTH = 5;

    /** Auf NR_LENGTH Stellen auffüllen; längere Nummern bleiben unverändert. */
    public static function formatNr(int|string|null $nr): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $nr) ?? '';
        if ($digits === '') {
            return null;
        }

        return str_pad(ltrim($digits, '0') ?: '0', self::NR_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Ohne Kundennummer bleibt nur die laufende Nummer – die VA-ID wird
     * vollständig, sobald der Veranstalter eine Kundennummer bekommt.
     */
    public static function buildVaId(?string $customerNo, int|string|null $nr): ?string
    {
        $nrPart = self::formatNr($nr);
        if ($nrPart === null) {
            return null;
        }

        return (preg_replace('/\D/', '', (string) $customerNo) ?? '') . $nrPart;
    }

    public static function nrToInt(int|string|null $nr): int
    {
        return (int) preg_replace('/\D/', '', (string) $nr);
    }

    /**
     * Nächste freie laufende Nummer. Sperrt die Events bis zum Ende der
     * Transaktion, damit zwei gleichzeitige Anlagen nicht dieselbe bekommen.
     */
    public static function next(): string
    {
        $max = Event::query()->lockForUpdate()->pluck('va_nr')
            ->map(fn (mixed $nr): int => self::nrToInt($nr))
            ->max() ?? 0;

        return (string) self::formatNr($max + 1);
    }

    public static function customerNoOf(?int $promoterId): ?string
    {
        return $promoterId ? Promoter::query()->whereKey($promoterId)->value('customer_no') : null;
    }
}
