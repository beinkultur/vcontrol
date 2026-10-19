{{--
    Kopf der Mails (x-mail::header): das Logo der Venue (App\Support\VenueLogo),
    eingepasst in 200 × 60 px und eingebettet wie das VenueControl-Logo im Fuß
    (html/logo). Ohne Logo oder ohne $mail – etwa in einer Vorschau über
    MailMessage::render() – steht $name da. $alt: Name der Venue, wenn $name
    etwas anderes ist (Passwort-Mail: „VenueControl“). Die Textfassung
    (text/venue) schreibt immer $name.
--}}
@props(['mail' => null, 'name' => '', 'alt' => null])
@php
    $logo = $mail ? \App\Support\VenueLogo::fitted(200, 60) : null;
@endphp
@if ($logo)
<img src="{{ $mail->embedData($logo['content'], $logo['name'], $logo['mime']) }}" width="{{ (int) round($logo['width']) }}" height="{{ (int) round($logo['height']) }}" alt="{{ $alt ?? $name }}" style="border: 0;">
@else
{{ $name }}
@endif
