<?php

namespace App\Mail;

use App\Models\BackupRun;
use App\Support\MailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An die System-Admins, wenn die nächtliche Sicherung scheitert (App\Support\Backups). */
class BackupFailed extends Mailable
{
    /** @param  list<BackupRun>  $runs  die gescheiterten Läufe */
    public function __construct(public array $runs, public string $host)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: MailIdentity::from(),
            subject: 'VenueControl: Sicherung fehlgeschlagen (' . $this->host . ')',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.backup-failed');
    }
}
