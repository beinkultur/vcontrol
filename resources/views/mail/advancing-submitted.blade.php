{{-- Mail an den Planer: Fragenkatalog abgegeben (App\Mail\AdvancingSubmittedMail). Nicht einrücken: Markdown. --}}
<x-mail::message>
# Advancing abgegeben

Der Veranstalter hat den Fragenkatalog „{{ $templateName }}“ für **{{ $eventTitle }}** am {{ $eventDate }} abgeschickt{{ $submitCount > 1 ? ' – zum ' . $submitCount . '. Mal' : '' }}.

{{ $changedCount === 0 ? 'Gegenüber eurer Vorbelegung hat er nichts geändert.' : ($changedCount === 1 ? 'Eine Angabe weicht von eurer Vorbelegung ab.' : $changedCount . ' Angaben weichen von eurer Vorbelegung ab.') }}

<x-mail::button :url="$link">
Im Event ansehen
</x-mail::button>

{{ $venue }}
</x-mail::message>
