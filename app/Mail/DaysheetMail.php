<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Daysheet-Mail (App\Support\Daysheets): Betreff und Text so, wie der
 * Eventmanager sie im Versand-Dialog freigegeben hat, mit dem Link darin.
 * Antworten gehen an den Absender im Team. Reine Textmail: Die Vorlage gibt den
 * Text ungefiltert aus, sonst stünde „&amp;“ statt „&“ darin.
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
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: filled($this->replyToAddress) ? [new Address($this->replyToAddress, $this->replyToName)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.daysheet');
    }
}
