{{--
    Ein Kalender-Eintrag als Balken, Block oder Listenzeile (filament/pages/calendar):
    Link zum Event bzw. zur Anfrage; eigene Einträge öffnen intern einen Dialog;
    sonst nicht klickbar. Farbe aus der Ebene (--vc-cal-color).
--}}
@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        $class,
        'vc-cal__item--' . $item->kind,
        'is-fraglich' => $item->status === 'fraglich',
        'is-cancelled' => $item->status === 'cancelled',
        'is-linked' => $extern && $item->linked,
        'is-unlinked' => $extern && $item->kind === \App\Support\CalendarItem::EVENT && !$item->linked,
        ...$modifiers,
    ]);
    $css = trim($style . '; --vc-cal-color: ' . ($colors[$item->layer] ?? '#64748b'), '; ');
@endphp
@if ($item->url !== null)
    <a href="{{ $item->url }}" wire:navigate class="{{ $classes }}" style="{{ $css }}" title="{{ $item->tooltip }}">{{ $text }}</a>
@elseif ($item->kind === \App\Support\CalendarItem::ENTRY && !$extern)
    <button type="button" wire:click="mountAction('entry', { id: {{ (int) $item->entryId }} })" class="{{ $classes }}" style="{{ $css }}" title="{{ $item->tooltip }}">{{ $text }}</button>
@else
    <span class="{{ $classes }}" style="{{ $css }}" title="{{ $item->tooltip }}">{{ $text }}</span>
@endif
