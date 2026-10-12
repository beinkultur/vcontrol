{{--
    HTML-Fassung der Daysheet-Mail (App\Mail\DaysheetMail), aufgebaut wie Laravels
    Mails (etwa „Passwort vergessen“). Der Text aus dem Versand-Dialog steht als
    eigenes HTML in <p>, damit Markdown ihn nicht umdeutet (ein „*“ oder „#“ bleibt,
    was es ist); e() maskiert ihn. Nicht einrücken: Markdown macht sonst Code daraus.
--}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$link">
{{ $venue }}
</x-mail::header>
</x-slot:header>

@foreach ($before as $paragraph)
<p>{!! nl2br(e($paragraph), false) !!}</p>

@endforeach
<x-mail::button :url="$link">
Daysheet öffnen
</x-mail::button>

@foreach ($after as $paragraph)
<p>{!! nl2br(e($paragraph), false) !!}</p>

@endforeach
<x-slot:subcopy>
<x-mail::subcopy>
Sollten Sie Schwierigkeiten haben, die Schaltfläche „Daysheet öffnen“ zu klicken, kopieren Sie den nachfolgenden Link in die Adresszeile Ihres Browsers: <span class="break-all">[{{ $link }}]({{ $link }})</span>
</x-mail::subcopy>
</x-slot:subcopy>

<x-slot:footer>
<x-mail::footer>
{{ $venue }} · erstellt mit [VenueControl]({{ config('venuecontrol.website') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
