<?php

namespace App\Mail;

use App\Support\MailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Der Veranstalter hat den Fragenkatalog abgegeben (App\Support\Advancings::submit):
 * an den Planer, der den Link verschickt hat, und die Antwortadresse der Venue.
 * Bewusst ohne die Antworten selbst – die stehen im Event unter Planung › Advancing.
 */
class AdvancingSubmittedMail extends Mailable
{
    public function __construct(
        public string $eventTitle,
        public string $eventDate,
        public string $templateName,
        public int $submitCount,
        public int $changedCount,
        public string $link,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: MailIdentity::from(),
            subject: "Advancing abgegeben: {$this->eventTitle} – {$this->eventDate}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.advancing-submitted',
            with: ['venue' => MailIdentity::from()->name],
        );
    }
}
