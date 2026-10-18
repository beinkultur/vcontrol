{{-- Textfassung der Zugangsdaten (App\Mail\AccessDataMail); ungefiltert, sonst stünde „&amp;“ statt „&“ darin --}}
Guten Tag {!! $accountName !!},

{!! $isNew ? 'für Sie gibt es jetzt einen Zugang zu VenueControl bei ' . $venue . '.' : 'Ihr Passwort für VenueControl bei ' . $venue . ' wurde neu vergeben.' !!} Damit melden Sie sich an:

Anmeldung:      {!! $loginUrl !!}
E-Mail-Adresse: {!! $accountEmail !!}
Passwort:       {!! $password !!}

Bitte ändern Sie das Passwort nach der ersten Anmeldung: Menü oben rechts › Profil › Passwort ändern. Ein vergessenes Passwort setzen Sie auf der Anmeldeseite über „Passwort vergessen?“ selbst zurück.

Diese E-Mail enthält Ihr Passwort – bitte nicht weiterleiten und löschen, sobald Sie es geändert haben.

--
{!! $venue !!} · erstellt mit VenueControl ({!! config('venuecontrol.website') !!})
