<?php

namespace App\Mail;

use App\Models\Damage;
use App\Support\MailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Hinweis auf eine neue Schadensmeldung wie DamageNotifier der PHP-Version. */
class DamageReported extends Mailable
{
    public function __construct(public Damage $damage, public string $link)
    {
    }

    public function envelope(): Envelope
    {
        $replyTo = MailIdentity::replyTo();

        return new Envelope(
            from: MailIdentity::from(),
            replyTo: $replyTo !== null ? [$replyTo] : [],
            subject: 'Neue Schadensmeldung' . ($this->damage->event ? ': ' . $this->damage->event->title : ''),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.damage-reported');
    }
}
