{{--
    Logo im Fuß der Mails, unter der Zeile „… erstellt mit VenueControl“
    (mail.daysheet-html, vendor/notifications/email). Eingebettet ($mail->embed),
    so zeigen es auch Mailprogramme, die Bilder aus dem Netz erst nach Rückfrage
    laden. Die Textfassung (text/logo) bleibt leer. Ohne $mail – etwa in einer
    Vorschau über MailMessage::render() – kein Logo.
--}}
@props(['mail' => null])
@if ($mail)
<a href="{{ config('venuecontrol.website') }}" style="text-decoration: none;"><img src="{{ $mail->embed(public_path('images/logo-mail.png')) }}" width="40" height="40" alt="VenueControl" style="border: 0;"></a>
@endif
