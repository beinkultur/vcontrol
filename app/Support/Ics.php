<?php

namespace App\Support;

/**
 * Bausteine für ICS-Feeds nach RFC 5545 (EventIcsFeed, PersonalIcsFeed):
 * Zeilen mit CRLF, auf 75 Oktett gefaltet, Werte maskiert.
 */
final class Ics
{
    private const EOL = "\r\n";

    /** @param  list<string>  $lines */
    public static function render(array $lines): string
    {
        return implode('', array_map(fn (string $line): string => self::fold($line) . self::EOL, $lines));
    }

    /**
     * Sonderzeichen maskieren. Jeder Zeilenumbruch (auch ein einzelnes CR) wird
     * zu „\n“, andere Steuerzeichen fallen weg – sonst ließe sich über einen
     * Titel eine eigene Zeile in den Feed schreiben.
     */
    public static function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value) ?? '';

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $value);
    }

    /** Zeilen auf 75 Oktett falten; nach Zeichen schneiden, damit kein UTF-8 zerrissen wird. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $limit = $out === '' ? 75 : 74;
            if (strlen($current) + strlen($char) > $limit) {
                $out .= ($out === '' ? '' : self::EOL . ' ') . $current;
                $current = '';
            }
            $current .= $char;
        }

        return $current === '' ? $out : $out . ($out === '' ? '' : self::EOL . ' ') . $current;
    }
}
