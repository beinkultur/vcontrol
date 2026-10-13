<?php

namespace App\Support;

/**
 * Beträge in deutscher Schreibweise: „2.800,50“ im Formular (Punkt für Tausender,
 * Komma für Cent), Zahlen in der Datenbank. parse() versteht beides – was aus der
 * Datenbank kommt („2800.5“) und was im Formular steht („2.800,50“).
 */
final class Money
{
    public static function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $value = str_replace([' ', "\u{00A0}", '€'], '', trim((string) $value));
        if ($value === '' || $value === '-') {
            return null;
        }
        if (str_contains($value, ',')) {
            // Deutsch: Punkte trennen Tausender, das Komma die Cent
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value) === 1) {
            // „2.800“: nur Tausenderpunkte
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /** Für das Eingabefeld: „2.800,00“; leer bleibt leer */
    public static function input(mixed $value): ?string
    {
        $number = self::parse($value);

        return $number === null ? null : number_format($number, 2, ',', '.');
    }
}
