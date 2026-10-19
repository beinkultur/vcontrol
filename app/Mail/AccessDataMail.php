<?php

namespace App\Mail;

use App\Support\MailIdentity;
use Filament\Facades\Filament;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Zugangsdaten eines Kontos (App\Support\AccessData): Adresse der Anmeldung,
 * E-Mail-Adresse und Passwort – für ein neues Konto oder nach einem neuen
 * Passwort. Absender ist die Venue (MailIdentity), Antworten gehen an ihre
 * Antwortadresse, sonst an den Admin, der verschickt hat. Aufgebaut wie die
 * Daysheet-Mail: HTML im Stil von Laravels Mails und reiner Text.
 */
class AccessDataMail extends Mailable
{
    public function __construct(
        public string $accountName,
        public string $accountEmail,
        public string $password,
        public bool $isNew,
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
            subject: ($this->isNew ? 'Ihr Zugang zu VenueControl' : 'Ihr neues Passwort für VenueControl') . ' – ' . MailIdentity::from()->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.access-data-html',
            text: 'mail.access-data',
            with: [
                'venue' => MailIdentity::from()->name,
                'loginUrl' => Filament::getPanel('app')->getLoginUrl(),
            ],
        );
    }
}
