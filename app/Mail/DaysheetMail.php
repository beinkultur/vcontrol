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
 * Absender ist die Halle (MailIdentity). Antworten gehen an die Antwortadresse
 * der Halle, ohne sie an den Eventmanager, der verschickt hat. Reine Textmail:
 * Die Vorlage gibt den Text ungefiltert aus, sonst stünde „&amp;“ statt „&“ darin.
 */
class DaysheetMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $text,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
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
        return new Content(text: 'mail.daysheet');
    }
}
