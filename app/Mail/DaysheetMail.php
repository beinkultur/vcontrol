<?php

namespace App\Mail;

use App\Support\MailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Daysheet-Mail (App\Support\Daysheets): Betreff und Text so, wie der
 * Eventmanager sie im Versand-Dialog freigegeben hat, mit dem Link darin.
 * Absender ist die Venue (MailIdentity). Antworten gehen an die Antwortadresse
 * der Venue, ohne sie an den Eventmanager, der verschickt hat.
 *
 * Zwei Fassungen: HTML im Stil von Laravels Mails (wie „Passwort vergessen“),
 * mit der Schaltfläche „Daysheet öffnen“ an der Stelle des Links, und reiner
 * Text für Mailprogramme ohne HTML. Die Textvorlage gibt den Text ungefiltert
 * aus, sonst stünde „&amp;“ statt „&“ darin.
 */
class DaysheetMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $text,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
        public string $link = '',
    ) {
    }

    public function envelope(): Envelope
    {
        $replyTo = MailIdentity::replyTo()
            ?? (filled($this->replyToAddress) ? new Address($this->replyToAddress, $this->replyToName) : null);

        return new Envelope(
            from: MailIdentity::from(),
            replyTo: $replyTo !== null ? [$replyTo] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        // Text vor und nach dem Link; ohne Link steht die Schaltfläche am Ende
        [$before, $after] = $this->link !== '' && str_contains($this->text, $this->link)
            ? explode($this->link, $this->text, 2)
            : [$this->text, ''];

        return new Content(
            markdown: 'mail.daysheet-html',
            text: 'mail.daysheet',
            with: [
                'venue' => MailIdentity::from()->name,
                'before' => self::paragraphs($before),
                'after' => self::paragraphs($after),
            ],
        );
    }

    /** Absätze an Leerzeilen; Zeilenumbrüche darin bleiben (nl2br in der Vorlage). @return list<string> */
    private static function paragraphs(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/', trim($text)) ?: []),
            fn (string $paragraph): bool => $paragraph !== '',
        ));
    }
}
