{{--
    HTML-Fassung der Costing-Mail (App\Mail\CostingMail), aufgebaut wie die
    Daysheet-Mail: im Kopf das Logo der Halle (sonst ihr Name), Text aus dem
    Versand-Dialog als eigenes HTML in <p> (e() maskiert, Markdown deutet nichts
    um), das PDF hängt an. Nicht einrücken: Markdown macht sonst Code daraus.
--}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
<x-mail::venue :mail="$message" :name="$venue" />
</x-mail::header>
</x-slot:header>

@foreach ($paragraphs as $paragraph)
<p>{!! nl2br(e($paragraph), false) !!}</p>

@endforeach
<x-slot:footer>
<x-mail::footer>
{{ $venue }} · erstellt mit [VenueControl]({{ config('venuecontrol.website') }})

<x-mail::logo :mail="$message" />
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
