<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\PromoterContact;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ansprechpartner vor Ort eines Events (Planung › Kontakte): seit 19.10.2026
 * Vorname, Name, Telefon und E-Mail statt eines Textfelds. parse() teilt den
 * alten Freitext auf (Migration, Importe); suggestions(), search() und pick()
 * bedienen das Suchfeld, das die Daten aus Kontakten, Ansprechpartnern der
 * Veranstalter und Gewerken übernimmt – übernommen wird eine Kopie, die am
 * Event bleibt, auch wenn sich der Eintrag in den Stammdaten ändert.
 */
final class OnsiteContact
{
    /** Spalten am Event mit ihrer Länge */
    public const FIELDS = ['onsite_first_name' => 100, 'onsite_last_name' => 120, 'onsite_phone' => 50, 'onsite_email' => 255];

    /** Treffer je Gruppe im Suchfeld */
    private const LIMIT = 15;

    /**
     * Freitext wie „Max Mustermann (0170-5555)“ oder „Jan Ludwig (KAN),
     * jan@example.com“ → Vorname, Name, Telefon, E-Mail. Telefon ist die erste
     * Ziffernfolge mit mindestens sechs Ziffern, E-Mail die erste Adresse; vom
     * Rest wird das erste Wort der Vorname, alles danach der Name, ein einzelnes
     * Wort („Produktionsleitung“) der Name. „Mustermann, Max“ wird gedreht.
     *
     * @return array{onsite_first_name: ?string, onsite_last_name: ?string, onsite_phone: ?string, onsite_email: ?string}
     */
    public static function parse(?string $text): array
    {
        $text = self::clean($text);
        $email = null;
        $phone = null;

        if (preg_match('/[^\s()<>,;:\[\]]+@[^\s()<>,;:\[\]]+\.[^\s()<>,;:\[\].]{2,}/u', $text, $m) === 1) {
            $email = $m[0];
            $text = str_replace($m[0], ' ', $text);
        }
        if (preg_match_all('/\+?\d[\d \/.\-]{4,}\d/u', $text, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                if (preg_match_all('/\d/', $candidate) >= 6) {
                    $phone = trim($candidate);
                    $text = str_replace($candidate, ' ', $text);
                    break;
                }
            }
        }

        // Was von Telefon und E-Mail übrig bleibt: „Tel.“, „Mail:“, leere Klammern, Trenner
        $text = (string) preg_replace('/(?<![\p{L}])(tel(efon)?|mobil|handy|fon|phone|e-?mail|mail)(?![\p{L}])\.?:?/iu', ' ', $text);
        do {
            $before = $text;
            $text = (string) preg_replace('/\(\s*[,;:\/|–-]*\s*\)/u', ' ', $text);
            // Trenner an den Enden; per Regex, trim() arbeitet byteweise und träfe Teile von „–“
            $text = (string) preg_replace(['/\s+/u', '/^[\s,;:\/|–-]+|[\s,;:\/|–-]+$/u'], [' ', ''], $text);
        } while ($text !== $before);

        [$first, $last] = self::splitName($text);

        return self::limit([
            'onsite_first_name' => $first,
            'onsite_last_name' => $last,
            'onsite_phone' => $phone,
            'onsite_email' => $email,
        ]);
    }

    /**
     * „Vorname Name“ → [Vorname, Name]; ein einzelnes Wort ist der Name.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitName(?string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', self::clean($name)));
        if ($name === '') {
            return [null, null];
        }
        if (preg_match('/^([^\s,]+),\s*([^\s,]+)$/u', $name, $m) === 1) {
            return [$m[2], $m[1]];
        }
        $words = explode(' ', $name);
        if (count($words) === 1) {
            return [null, $name];
        }

        return [array_shift($words), implode(' ', $words)];
    }

    /** Der alte Freitext aus den vier Feldern, z. B. für das Zurückdrehen der Migration. */
    public static function text(?string $first, ?string $last, ?string $phone, ?string $email): ?string
    {
        $name = trim(trim((string) $first) . ' ' . trim((string) $last));
        $extra = implode(', ', array_filter([trim((string) $phone), trim((string) $email)], 'filled'));
        $text = trim($name . ($extra !== '' ? ($name !== '' ? ' (' . $extra . ')' : $extra) : ''));

        return $text === '' ? null : mb_substr($text, 0, 120);
    }

    /**
     * Ohne Suchbegriff: die Ansprechpartner des Veranstalters dieses Events.
     *
     * @return array<string, array<string, string>>
     */
    public static function suggestions(?int $promoterId): array
    {
        if ($promoterId === null) {
            return [];
        }
        $contacts = PromoterContact::query()->with('promoter')->where('promoter_id', $promoterId)
            ->orderBy('sort_order')->orderBy('last_name')->limit(self::LIMIT)->get();

        return array_filter(['Veranstalter' => $contacts->mapWithKeys(fn (PromoterContact $c): array => ['promoter_contact:' . $c->id => self::promoterContactLabel($c)])->all()]);
    }

    /**
     * Suche über Ansprechpartner der Veranstalter (auch nach dem Namen des
     * Veranstalters), Kontakte und Gewerke – jedes Wort muss irgendwo passen.
     * Archivierte stehen nicht zur Wahl; der Veranstalter des Events zuerst.
     *
     * @return array<string, array<string, string>>
     */
    public static function search(string $search, ?int $promoterId): array
    {
        $words = array_values(array_filter(explode(' ', self::clean($search)), 'filled'));
        if ($words === []) {
            return self::suggestions($promoterId);
        }
        $matches = function (Builder $query, array $columns) use ($words): Builder {
            foreach ($words as $word) {
                $like = '%' . addcslashes(mb_strtolower($word), '%_\\') . '%';
                $query->where(function (Builder $q) use ($columns, $like): void {
                    foreach ($columns as $column) {
                        $q->orWhereRaw('LOWER(' . $column . ') LIKE ?', [$like]);
                    }
                });
            }

            return $query;
        };

        $promoterContacts = $matches(
            PromoterContact::query()->select('promoter_contacts.*')->with('promoter')
                ->join('promoters', 'promoters.id', '=', 'promoter_contacts.promoter_id')
                ->where('promoters.is_archived', false),
            ['promoter_contacts.first_name', 'promoter_contacts.last_name', 'promoter_contacts.email', 'promoter_contacts.phone', 'promoter_contacts.role', 'promoters.name', 'promoters.short_name'],
        )
            ->when($promoterId !== null, fn (Builder $q) => $q->orderByRaw('CASE WHEN promoter_contacts.promoter_id = ? THEN 0 ELSE 1 END', [$promoterId]))
            ->orderBy('promoter_contacts.last_name')->orderBy('promoter_contacts.first_name')
            ->limit(self::LIMIT)->get();
        $contacts = $matches(Contact::query()->where('is_archived', false), ['name', 'email', 'phone'])
            ->orderByRaw('LOWER(name)')->limit(self::LIMIT)->get();
        $trades = $matches(Trade::query()->where('is_archived', false), ['name', 'short_name', 'email', 'phone'])
            ->orderByRaw('LOWER(name)')->limit(self::LIMIT)->get();

        return array_filter([
            'Veranstalter' => $promoterContacts->mapWithKeys(fn (PromoterContact $c): array => ['promoter_contact:' . $c->id => self::promoterContactLabel($c)])->all(),
            'Kontakte' => $contacts->mapWithKeys(fn (Contact $c): array => ['contact:' . $c->id => self::withDetails((string) $c->name, $c->phone, $c->email)])->all(),
            'Gewerke' => $trades->mapWithKeys(fn (Trade $t): array => ['trade:' . $t->id => self::withDetails(self::tradeName($t), $t->phone, $t->email)])->all(),
        ]);
    }

    /**
     * Die Felder für den gewählten Eintrag („promoter_contact:5“, „contact:3“,
     * „trade:2“), null für einen unbekannten. Kontakte haben nur einen Namen –
     * der wird geteilt wie bei parse(); ein Gewerk kommt als Name.
     *
     * @return array{onsite_first_name: ?string, onsite_last_name: ?string, onsite_phone: ?string, onsite_email: ?string}|null
     */
    public static function pick(?string $value): ?array
    {
        [$type, $id] = array_pad(explode(':', (string) $value, 2), 2, null);
        $id = (int) $id;

        $fields = match ($type) {
            'promoter_contact' => ($c = PromoterContact::query()->find($id)) === null ? null
                : [self::clean($c->first_name) ?: null, self::clean($c->last_name) ?: null, $c->phone, $c->email],
            'contact' => ($c = Contact::query()->find($id)) === null ? null
                : [...self::splitName($c->name), $c->phone, $c->email],
            'trade' => ($t = Trade::query()->find($id)) === null ? null
                : [null, self::clean($t->name) ?: null, $t->phone, $t->email],
            default => null,
        };
        if ($fields === null) {
            return null;
        }

        return self::limit(array_combine(array_keys(self::FIELDS), array_map(
            fn (?string $v): ?string => ($v = self::clean($v)) === '' ? null : $v,
            $fields,
        )));
    }

    private static function promoterContactLabel(PromoterContact $contact): string
    {
        $name = trim(self::clean($contact->first_name) . ' ' . self::clean($contact->last_name));
        $promoter = $contact->promoter?->name;

        return self::withDetails(
            ($name !== '' ? $name : 'Ohne Namen') . (filled($promoter) ? ' – ' . $promoter : '') . (filled($contact->role) ? ' (' . $contact->role . ')' : ''),
            $contact->phone,
            $contact->email,
        );
    }

    private static function tradeName(Trade $trade): string
    {
        return filled($trade->short_name) && $trade->short_name !== $trade->name ? $trade->name . ' (' . $trade->short_name . ')' : (string) $trade->name;
    }

    private static function withDetails(string $label, ?string $phone, ?string $email): string
    {
        $details = implode(' · ', array_filter([self::clean($phone), self::clean($email)], 'filled'));

        return $details === '' ? $label : $label . ' · ' . $details;
    }

    /** Ohne unsichtbare Zeichen (AppSheet hat welche in Adressen) und doppelte Leerzeichen */
    private static function clean(?string $value): string
    {
        $value = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}]/u', '', (string) $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param  array<string, ?string>  $fields
     * @return array{onsite_first_name: ?string, onsite_last_name: ?string, onsite_phone: ?string, onsite_email: ?string}
     */
    private static function limit(array $fields): array
    {
        foreach (self::FIELDS as $field => $length) {
            $value = trim((string) ($fields[$field] ?? ''));
            $fields[$field] = $value === '' ? null : mb_substr($value, 0, $length);
        }

        return $fields;
    }
}
