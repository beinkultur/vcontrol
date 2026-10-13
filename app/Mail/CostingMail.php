<?php

namespace App\Mail;

use App\Support\MailIdentity;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Costing-Mail (App\Support\Costings): Betreff und Text aus dem Versand-Dialog,
 * das Costing als PDF im Anhang. Absender und Antwortadresse wie beim Daysheet
 * (DaysheetMail): die Halle, Antworten an ihre Antwortadresse, ohne sie an den,
 * der verschickt hat.
 */
class CostingMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $text,
        public string $pdf,
        public string $fileName,
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
        return new Content(
            markdown: 'mail.costing-html',
            text: 'mail.costing',
            with: [
                'venue' => MailIdentity::from()->name,
                'paragraphs' => array_values(array_filter(
                    array_map('trim', preg_split('/\n\s*\n/', trim($this->text)) ?: []),
                    fn (string $paragraph): bool => $paragraph !== '',
                )),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
