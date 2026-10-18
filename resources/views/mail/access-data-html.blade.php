{{--
    HTML-Fassung der Zugangsdaten (App\Mail\AccessDataMail), aufgebaut wie die
    Daysheet-Mail: im Kopf das Logo der Halle, im Fuß „… erstellt mit
    VenueControl“ mit dessen Logo. Texte und Werte stehen als eigenes HTML mit
    e(), damit Markdown sie nicht umdeutet – ein „*“ oder „_“ im Passwort bleibt,
    was es ist. Nicht einrücken: Markdown macht sonst Code daraus.
--}}
@php
    $label = 'padding: 6px 16px 6px 0; color: #718096; white-space: nowrap; vertical-align: top;';
    $value = 'padding: 6px 0; vertical-align: top; word-break: break-all;';
@endphp
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$loginUrl">
<x-mail::venue :mail="$message" :name="$venue" />
</x-mail::header>
</x-slot:header>

<p>Guten Tag {{ $accountName }},</p>

<p>{{ $isNew ? 'für Sie gibt es jetzt einen Zugang zu VenueControl bei ' . $venue . '.' : 'Ihr Passwort für VenueControl bei ' . $venue . ' wurde neu vergeben.' }} Damit melden Sie sich an:</p>

<table role="presentation" cellpadding="0" cellspacing="0" style="width: 100%; margin: 0 0 21px; border-collapse: collapse;">
<tr><td style="{{ $label }}">Anmeldung</td><td style="{{ $value }}"><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></td></tr>
<tr><td style="{{ $label }}">E-Mail-Adresse</td><td style="{{ $value }}">{{ $accountEmail }}</td></tr>
<tr><td style="{{ $label }}">Passwort</td><td style="{{ $value }} font-family: Menlo, Consolas, 'Courier New', monospace; font-size: 16px; color: #1a202c;">{{ $password }}</td></tr>
</table>

<x-mail::button :url="$loginUrl">
Anmelden
</x-mail::button>

<p>Gleich nach der Anmeldung legen Sie ein eigenes Passwort fest; das aus dieser E-Mail brauchen Sie danach nicht mehr. Ein vergessenes Passwort setzen Sie später auf der Anmeldeseite über „Passwort vergessen?“ selbst zurück.</p>

<p>Diese E-Mail enthält Ihr Passwort – bitte nicht weiterleiten und löschen, sobald Sie ein eigenes festgelegt haben.</p>

<x-slot:footer>
<x-mail::footer>
{{ $venue }} · erstellt mit [VenueControl]({{ config('venuecontrol.website') }})

<x-mail::logo :mail="$message" />
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
