<?php

namespace App\Support;

use App\Mail\GuestListMail;
use App\Models\Event;
use App\Models\EventGuest;
use App\Models\Setting;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Gästeliste als PDF und per Mail (seit 19.10.2026). Das PDF ist immer DIN A4
 * hoch, mit Dompdf auf dem Server gesetzt – unabhängig davon, welches Format
 * ein Browser beim „Als PDF speichern“ nähme. Es hängt auch an der Mail.
 * Absender und Antwortadresse wie beim Costing (MailIdentity).
 */
final class GuestLists
{
    public const DEFAULT_SUBJECT = 'Gästeliste {event} – {datum}';

    public const DEFAULT_TEXT = "Hallo,\n\nanbei die Gästeliste für {event} am {datum}: {gaeste} Gäste mit {freikarten} Freikarten.\n\nViele Grüße\n{venue}";

    public const PLACEHOLDER_HELP = '{event}, {datum}, {venue}, {gaeste} und {freikarten} werden ersetzt.';

    /** @return Collection<int, EventGuest> nach Name und Vorname wie in der Druckansicht */
    public static function guests(Event $event): Collection
    {
        return $event->guests()->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();
    }

    /** Platzhalter des Daysheets ({event}, {datum}, {venue}), dazu {gaeste} und {freikarten} */
    public static function fill(string $text, Event $event): string
    {
        $guests = self::guests($event);

        return strtr(Daysheets::fill($text, $event), [
            '{gaeste}' => (string) $guests->count(),
            '{freikarten}' => (string) (int) $guests->sum('free_tickets'),
        ]);
    }

    /**
     * Vorbelegung des Versand-Dialogs; in Kopie (BCC) der, der verschickt.
     *
     * @return array{to: list<string>, bcc: list<string>, subject: string, body: string}
     */
    public static function defaults(Event $event, ?User $sender): array
    {
        return [
            'to' => [],
            'bcc' => $sender !== null ? Daysheets::emails([$sender->email]) : [],
            'subject' => self::fill(self::DEFAULT_SUBJECT, $event),
            'body' => self::fill(self::DEFAULT_TEXT, $event),
        ];
    }

    /** PDF in DIN A4 hoch: Kopf mit Event, Tabelle (Kopfzeile auf jeder Seite), Summe, Seitenzahlen */
    public static function pdf(Event $event): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([base_path('vendor/dompdf/dompdf')]);

        $guests = self::guests($event);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('guest-list-pdf', [
            'event' => $event->loadMissing('promoter'),
            'guests' => $guests,
            'ticketTotal' => (int) $guests->sum('free_tickets'),
            'venue' => Setting::lookup(Setting::VENUE_NAME) ?: (string) config('app.name'),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 34, 'Seite {PAGE_NUM} von {PAGE_COUNT}', $font, 7, [0.47, 0.47, 0.47]);

        return (string) $dompdf->output();
    }

    /** „Gästeliste Rock im Herbst 2026-10-24.pdf“ */
    public static function fileName(Event $event): string
    {
        $title = trim((string) preg_replace(['/[^\pL\pN .,&()+-]+/u', '/\s+/'], ' ', (string) $event->title));

        return trim(implode(' ', array_filter(['Gästeliste', $title, $event->starts_at?->format('Y-m-d')]))) . '.pdf';
    }

    /**
     * Verschicken: eine Mail an „An“, die übrigen im BCC, das PDF im Anhang. Im
     * Änderungsprotokoll des Events steht, an wen.
     *
     * @param  list<string>  $to
     * @param  list<string>  $bcc
     */
    public static function send(Event $event, array $to, array $bcc, string $subject, string $body, ?User $sender = null): void
    {
        $to = Daysheets::emails($to);
        $bcc = array_values(array_diff(Daysheets::emails($bcc), $to));
        if ($to === []) {
            throw new InvalidArgumentException('Die Gästeliste braucht mindestens einen Empfänger unter „An“.');
        }

        Mail::to($to)->bcc($bcc)->send(new GuestListMail(
            subjectLine: self::fill(trim($subject), $event),
            text: self::fill(str_replace(["\r\n", "\r"], "\n", $body), $event),
            pdf: self::pdf($event),
            fileName: self::fileName($event),
            replyToAddress: $sender?->email,
            replyToName: $sender?->getFilamentName(),
        ));

        Audit::record('events', Audit::UPDATED, null, ['guest_list_sent_to' => $to],
            key: (string) $event->getKey(), label: (string) $event->title, eventId: (int) $event->getKey());
    }
}
