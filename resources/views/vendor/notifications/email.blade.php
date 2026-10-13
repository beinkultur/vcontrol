{{--
    Benachrichtigungen per Mail (bisher nur „Passwort vergessen“): Laravels
    notifications::email, aber im Kopf das Logo der Halle (x-mail::venue, ohne
    Logo „VenueControl“) und mit dem Fuß der Daysheet-Mail – „Halle · erstellt
    mit VenueControl“, darunter das Logo (x-mail::logo). Deshalb das Layout
    direkt statt x-mail::message, dessen Fuß fest „© … Alle Rechte vorbehalten“
    ist. Dient auch als Textfassung; nicht einrücken, Markdown macht sonst Code
    daraus.
--}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
<x-mail::venue :mail="$message ?? null" :name="config('app.name')" :alt="\App\Support\MailIdentity::from()->name" />
</x-mail::header>
</x-slot:header>

{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ \App\Support\MailIdentity::from()->name }} · erstellt mit [VenueControl]({{ config('venuecontrol.website') }})

<x-mail::logo :mail="$message ?? null" />
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
