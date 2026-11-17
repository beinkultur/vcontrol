<?php

namespace App\Filament\Support;

use App\Models\EventAdvancing;
use App\Support\Advancings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Vorbelegung eines Advancings unter Planung › Advancing: Abschnitte, Infotexte
 * und Fragen aus der Kopie des Katalogs, Felder unter prefill.<key>. Hat der
 * Veranstalter etwas anderes eingetragen, steht es als Hinweis am Feld.
 */
final class AdvancingFields
{
    /**
     * @param  bool  $editable  false: nur lesen – Werte als Text, ohne Auswahllisten
     *     (die Personenliste mit Kontaktdaten kommt dann gar nicht erst ins HTML)
     * @return list<Component>
     */
    public static function schema(EventAdvancing $advancing, bool $editable = true): array
    {
        $prefill = (array) $advancing->prefill;
        $sections = [];
        foreach ((array) $advancing->structure as $s => $section) {
            $components = [];
            foreach ((array) ($section['items'] ?? []) as $i => $item) {
                if (($item['type'] ?? null) === 'info') {
                    $components[] = TextEntry::make("info_{$s}_{$i}")
                        ->label((string) ($item['label'] ?? ''))
                        ->state((string) ($item['text'] ?? ''))
                        ->color('gray');

                    continue;
                }
                $field = self::field($item, $editable, isset($prefill[$item['key']]) ? (string) $prefill[$item['key']] : null);
                $hint = self::changeHint($advancing, (string) $item['key']);
                if ($hint !== null) {
                    $field->hint($hint)->hintColor('warning')->hintIcon(Heroicon::OutlinedExclamationTriangle);
                }
                $components[] = $field;
            }
            $sections[] = Section::make((string) ($section['title'] ?? ''))
                ->description(filled($section['text'] ?? null) ? (string) $section['text'] : null)
                ->compact()
                ->collapsible()
                ->schema($components);
        }

        return $sections;
    }

    /** „Veranstalter: …“, wenn er bei dieser Frage etwas anderes eingetragen hat als die Vorbelegung; sonst null. */
    public static function changeHint(EventAdvancing $advancing, string $key): ?string
    {
        if (!in_array($key, Advancings::changedKeys($advancing), true)) {
            return null;
        }

        return 'Veranstalter: ' . Advancings::display($advancing->questions()[$key] ?? [], (string) ((array) $advancing->answers)[$key]);
    }

    /** Werte fürs Formular: die Vorbelegung unter prefill. @return array{prefill: array<string, string>} */
    public static function fill(EventAdvancing $advancing): array
    {
        return ['prefill' => array_map('strval', (array) $advancing->prefill)];
    }

    /**
     * Vorbelegung aus dem Formular: nur bekannte Fragen, leere fallen weg.
     *
     * @param  array<mixed>  $data
     * @return array<string, string>
     */
    public static function prefill(EventAdvancing $advancing, array $data): array
    {
        $questions = $advancing->questions();
        $prefill = [];
        foreach ((array) ($data['prefill'] ?? []) as $key => $value) {
            $value = Advancings::normalize(is_scalar($value) ? $value : '');
            if (isset($questions[$key]) && $value !== '') {
                $prefill[(string) $key] = $value;
            }
        }

        return $prefill;
    }

    /** Feld für eine Frage; nur lesen: Text mit dem angezeigten Wert. */
    public static function field(array $item, bool $editable = true, ?string $value = null): Component
    {
        $name = 'prefill.' . $item['key'];
        if (!$editable) {
            return TextEntry::make('show_' . $item['key'])
                ->label((string) $item['label'])
                ->state(Advancings::display($item, $value));
        }
        $field = match ($item['type'] ?? 'text') {
            'textarea' => Textarea::make($name)->rows(3)->maxLength(5000),
            'number' => TextInput::make($name)->integer()->minValue(0)->maxValue(9999),
            'date' => DatePicker::make($name)->native(false)->displayFormat('d.m.Y')->format('Y-m-d'),
            'yes_no' => Select::make($name)->options(['ja' => 'ja', 'nein' => 'nein']),
            'choice' => Select::make($name)->options(array_combine((array) $item['options'], (array) $item['options'])),
            'person' => Select::make($name)->options(fn (): array => Advancings::personOptions(array_filter([$value])))->searchable(),
            default => TextInput::make($name)->maxLength(500),
        };
        $hints = array_filter([
            ($item['locked'] ?? false) ? 'Veranstalter sieht nur' : null,
            filled($item['binding'] ?? null) ? 'aus dem Event' : null,
        ]);

        return $field
            ->label((string) $item['label'])
            ->helperText(trim((string) ($item['help'] ?? '') . ($hints !== [] ? ' (' . implode(', ', $hints) . ')' : '')) ?: null);
    }
}
