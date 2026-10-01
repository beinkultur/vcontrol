# VenueControl (Laravel)

Laravel-Neubau von VenueControl, der Veranstaltungsverwaltung für Hallen. Eine
Installation pro Halle mit eigener Subdomain und eigener Datenbank. Die erste ist
https://ipa.vcontrol.eu für die Inselpark Arena.

Die bestehende PHP-Version ohne Framework läuft parallel unter https://vc.bein.ws
(Repo `/Users/niggo/Nextcloud/_nb/VARIOUS/VenueControl`) und ist fachlich die Vorlage.
Ihre Datei `scripts/smoke_baseline.json` hält für jede Rolle und Seite den Statuscode
fest — die Rechte-Matrix, die diese Version erreichen muss.

## Arbeitsweise

- **Lokal ist weder PHP noch Composer installiert, und das bleibt so.** Composer,
  Artisan und Tests laufen auf dem Server:
  `ssh allinkl-eventmanager 'cd /www/htdocs/w0219ff1/ipa.vcontrol.eu && php artisan …'`
- `vendor/` und `.env` existieren nur auf dem Server. Pakete per `composer require`
  auf dem Server installieren und `composer.json` und `composer.lock` sofort zurück
  ins Repo holen – sonst überschreibt der nächste Deploy den neueren Stand.
- Deploy: `scripts/deploy.sh` (Standard ipa.vcontrol.eu, sonst die Halle als
  Parameter; `--dry-run` zeigt nur an). Überträgt per rsync, löscht auf dem
  Server, was im Repo gelöscht oder umbenannt wurde – nur in app, config,
  database, resources, routes, tests; Wurzelverzeichnis, public/, storage/ und
  vendor/ nie –, und leert die Caches. Bei mehr als 20 Löschungen bricht es vorher
  ab (`MAX_DELETE=n` hebt die Grenze). Neue Migrationen danach selbst mit
  `php artisan migrate --force` ausführen.
- Änderungsprotokoll: Alles, was über Eloquent gespeichert wird, protokolliert
  `App\Support\Audit` automatisch. Wer an Eloquent vorbei schreibt (`DB::table`,
  `->query()->update()/delete()`, Pivot ohne eigenes Modell), muss `Audit::record()`
  aufrufen – sonst fehlt die Änderung im Audit. Neue Tabellen in
  `AuditPresenter::SUBJECTS`, neue Felder in `FIELDS` eintragen.
- Für die Entwicklung kommen die Daten aus der PHP-Version: `php artisan vc:import --force`
  (in Produktion nur mit `--force`), beliebig oft wiederholbar – überschreibt aber
  alles, was hier eingegeben wurde. Vorher in der Tabelle `sessions` nachsehen, ob
  seit dem letzten Import jemand gearbeitet hat (Zeilen mit IP 127.0.0.1 und
  Browser „Symfony“ stammen von Prüfbefehlen), und dann erst fragen. Zum Go-live
  wird Laravel nicht aus der PHP-Version, sondern direkt aus dem AppSheet-Export
  befüllt (docs/EVENTS.md); dann `VC_IMPORT_LOCKED=true` setzen.
- Filament-Code gegen `vendor/filament` auf dem Server prüfen, nicht aus dem
  Gedächtnis von Version 3 schreiben: Version 5 hat andere Namespaces
  (`Filament\Schemas\…`, `Filament\Actions\…`).

## Filament-5-Fallen (hier schon einmal passiert)

- In Abschnitten mit `->relationship('finance')` ist `$record` in Closures das
  zugehörige Modell (`EventFinance`), nicht das Event.
- Bei `->options(SomeEnum::class)` liefert `$get()` ein Enum-Objekt, keinen
  Text – vor `(string)` auf `BackedEnum` prüfen.
- Relation Manager laden lazy (erst beim Hinscrollen); im ersten HTML steht
  nur ein Platzhalter.
- Felder, die nicht am Modell hängen: `->dehydrated(false)` plus
  `->saveRelationshipsUsing()` (siehe `App\Filament\Support\*Fields`).
- `afterStateHydrated()` läuft nach den State-Casts: Wer dort `state()` setzt,
  muss das Format selbst liefern (bei `ToggleButtons::boolean()` 1/0, nicht
  true/false – sonst ist nichts markiert).
- **Ausblenden schützt keine Daten.** Filament füllt auch verborgene Abschnitte
  (`->visible(false)`), samt `->relationship()` – die Werte stehen dann im
  Livewire-Zustand im Seitenquelltext. Was ein Benutzer nicht sehen darf, gar
  nicht erst ins Schema bauen (siehe Reiter „Buchhaltung“ in `EventForm`).
- Formulare bekommen beim Laden **alle** Spalten des Datensatzes in den Zustand,
  auch ohne Feld. Heikles in `mutateFormDataBeforeFill()` entfernen (siehe
  WLAN-Passwort in `ViewEvent`).
- Reiter in der URL: `Tabs::persistTabInQueryString('phase')` plus `Tab::id()`,
  sonst ist der Schlüssel ein Slug wie `buchung::data::tab`.
- Zwei Abschnitte mit `->relationship()` auf dieselbe 1:1-Beziehung legen bei
  neuen Events die Zeile doppelt an. Ein einzelnes Feld woanders: ohne Bindung
  und per `updateOrCreate` speichern (siehe Sold-Out-Award in `EventForm`).

## Tests

- `php artisan test` (und `php85 artisan test`) auf dem Server: PHPUnit gegen
  SQLite im Speicher, ~2 Sekunden. `tests/TestCase.php` bricht ab, falls die
  Verbindung nicht SQLite im Speicher ist – RefreshDatabase würde sonst echte
  Daten löschen.
- Filament-Dialoge mit `Livewire::test(...)->callAction(...)` prüfen. Nach
  abgelehnter Eingabe bleibt der Dialog offen: für den nächsten Aufruf eine
  frische Komponente nehmen. `fillForm()` wirkt nur mit APP_ENV=testing.
- Dateien (Event-Dateien) liegen auf der Disk „local“ (`storage/app/private`),
  nie unter `public/`; ausgeliefert über eine Route mit Rechteprüfung.
  Upload-Grenze 15 MB: `config/livewire.php` und `public/.user.ini`.
- Seitenbreite: Listen voll, Formulare/Dashboards mit `BoxedPage` (1400 px).
- Verfasser/Bearbeiter mit dauerhaftem Namen: Trait `App\Models\Concerns\StampsAuthor`
  (Spalten created_by, created_by_name, updated_by, updated_by_name).
- Unterschriften: Feld `App\Filament\Forms\SignaturePad` (Alpine im View, kein
  Build); Anzeige über `filament.events.signature-image`, nur geprüfte PNG-data:-URLs.
- Eigene Stile ohne Build-Schritt: `public/css/vcontrol.css`, eingebunden per
  Render-Hook im `AppPanelProvider`. Tailwind-Klassen in eigenen Blade-Dateien
  wirken nicht (Filament bringt nur die eigenen, fertig gebauten Klassen mit).
- Nach jeder Änderung: Tests grün, `php artisan vc:check-access` gleich,
  `php artisan vc:smoke` fehlerfrei. Letzteres rendert als Admin jede Seite mit den
  echten Daten (je Resource die neuesten Datensätze und den ältesten) und findet,
  was nur mit Altwerten aus dem Import schiefgeht. `--only=/events` grenzt ein.

## Rechte

- Bereiche und Stufen: `App\Access\Area`, `App\Access\Level`; was ein Benutzer darf:
  `$user->access()`. Mehrere Rollen addieren sich, je Bereich gilt die höchste
  Stufe. Abgeschaltete Module (`VC_DISABLED_AREAS`) sind auch für Admins zu.
- Jede Filament-Resource braucht eine Policy, am einfachsten als Unterklasse von
  `App\Policies\AreaPolicy`. Das Panel läuft mit `strictAuthorization()` – eine
  fehlende Policy-Methode ist ein Fehler, keine Freigabe.
- Neue Seiten in `App\Console\Commands\CheckAccess::PAGES` eintragen und
  `php artisan vc:check-access` laufen lassen: vergleicht je Rolle mit den
  Sollwerten der PHP-Version.
- Strenger als die PHP-Version: Admin-Konten und die Admin-Rolle ändern nur
  Admins; niemand löscht sich selbst; der letzte aktive Admin bleibt Admin.
  Allgemein: Niemand vergibt mehr Rechte, als er selbst hat (`Access::covers()`) –
  Rollen und Konten mit mehr Rechten ändert nur, wer diese Rechte hat
  (RolePolicy, UserPolicy), und beim Speichern prüfen `AccountSafety` bzw.
  `GrantsOnlyOwnRights` die neuen Stufen.
- Passwort ändert jeder selbst unter Profil (`App\Filament\Pages\Auth\EditProfile`,
  nur das Passwort); „Angemeldet bleiben“ gilt 30 Tage (`config/auth.php`).
- Das Audit zeigt jedem nur Einträge aus Bereichen, die er auch sonst lesen darf
  (`AuditPresenter::SUBJECT_AREAS`) – neue Tabellen dort eintragen, sonst sehen
  sie nur Admins.
- Sicherheits-Header und HTTPS-Zwang: `App\Http\Middleware\SecurityHeaders`.
  Dateien nie mit dem gespeicherten MIME-Typ inline ausliefern (siehe
  `EventFile::inlineType()`), FileUpload-Felder mit `->preventFilePathTampering()`.
- Die Server-CLI ist PHP 8.3 (`php`), daneben gibt es `php85`. Code muss unter beiden
  laufen.
- Oberfläche, Texte, Kommentare und Commit-Nachrichten auf Deutsch.
