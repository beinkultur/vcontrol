# VenueControl (Laravel)

Laravel-Neubau von VenueControl, der Veranstaltungsverwaltung für Hallen. Eine
Installation pro Halle mit eigener Subdomain und eigener Datenbank. Die erste ist
https://ipa.vcontrol.eu für die Inselpark Arena.

Fachliche Vorlage war die PHP-Version ohne Framework (vc.bein.ws). Sie ist seit
12.10.2026 gelöscht – Code auf GitHub `beinkultur/VenueControl`, ein Archiv mit
Dateien und Datenbank in der Nextcloud. Ihre Sollwerte je Rolle und Seite liegen
als `scripts/php-smoke-baseline.json` hier im Repo – die Rechte-Matrix, die diese
Version erreichen muss. Weiterentwickelt wird nur noch diese Laravel-Version.

## Arbeitsweise

- **Lokal ist weder PHP noch Composer installiert, und das bleibt so.** Composer,
  Artisan und Tests laufen auf dem Server:
  `ssh allinkl-eventmanager 'cd /www/htdocs/w0219ff1/ipa.vcontrol.eu && php artisan …'`
- `vendor/` und `.env` existieren nur auf dem Server. Neue Pakete: `composer.json`
  im Repo ändern, in einer abgeschotteten Testkopie auf dem Server mit **echter**
  Kopie von vendor (`cp -a`, nie `cp -al` – Composer schriebe sonst über die
  Hardlinks in die Live-Installation) `composer update <paket> --with-dependencies`,
  `composer.lock` zurück ins Repo holen, dort testen. Einspielen:
  `php artisan down --render="errors::503" --retry=60` (die eigene Wartungsseite,
  vorab gerendert – sie kommt auch, wenn die App mitten im Composer-Lauf nicht
  startet), `scripts/deploy.sh`, `composer install` (umask 022), Migrationen,
  `php artisan up`. So am 13.10.2026 mit dompdf. Ebenso bei Migrationen, die
  Spalten umbauen. Die Tests nutzen den Wartungsmodus „array“ (phpunit.xml) – sie
  laufen auch in der Live-Installation und dürfen die Datei
  `storage/framework/down` nie anlegen.
- Deploy: `scripts/deploy.sh` (Standard ipa.vcontrol.eu, sonst die Halle als
  Parameter; `--dry-run` zeigt nur an). Überträgt per rsync, löscht auf dem
  Server, was im Repo gelöscht oder umbenannt wurde – nur in app, config,
  database, resources, routes, tests; Wurzelverzeichnis, public/, storage/ und
  vendor/ nie –, und leert die Caches. Bei mehr als 20 Löschungen bricht es vorher
  ab (`MAX_DELETE=n` hebt die Grenze). Neue Migrationen danach selbst mit
  `php artisan migrate --force` ausführen.
- Beträge in Formularen mit `App\Filament\Forms\MoneyInput` (Tausenderpunkt beim
  Tippen, „2.800,50“); gerechnet wird mit `App\Support\Money::parse()`, das beide
  Schreibweisen versteht. Relation Manager, die eingebettet sind, prüft Filament
  selbst mit `canViewForRecord()` (sonst `viewAny` der Policy) – weicht das Recht
  für den Reiter davon ab, dort überschreiben (CostingsRelationManager).
- PDFs erzeugt Dompdf (`App\Support\CostingPdf`, Vorlage `costing-pdf`): nur CSS
  2.1 – Tabellen statt Flex/Grid, Schrift DejaVu Sans (€, Umlaute), Bilder als
  data-URI, fremde Adressen aus. Was zusammenbleiben soll, als eigene Tabelle in
  einem Block mit `page-break-inside: avoid` – auf `tbody` wirkt es nicht. Kopf-
  und Fußzeile jeder Seite: `position: fixed` mit negativem `top`/`bottom` im
  Seitenrand (`@page`), im `body` vor dem Inhalt.
- Composer und `php artisan filament:assets` nie unter `umask 077` laufen lassen.
  Die Dateien unter `public/` liefert der Webserver als anderer Benutzer aus; nur
  für den Eigentümer lesbar heißt 403, und die Oberfläche lädt ohne CSS und JS
  (so geschehen am 11.10.2026, zehn Stunden lang unbemerkt). Nach einem Update
  nicht nur die Login-Seite prüfen, sondern auch eine Asset-URL wie
  `/css/filament/filament/app.css` – `scripts/deploy.sh` macht beides am Ende.
- Änderungsprotokoll: Alles, was über Eloquent gespeichert wird, protokolliert
  `App\Support\Audit` automatisch. Wer an Eloquent vorbei schreibt (`DB::table`,
  `->query()->update()/delete()`, Pivot ohne eigenes Modell), muss `Audit::record()`
  aufrufen – sonst fehlt die Änderung im Audit. Neue Tabellen in
  `AuditPresenter::SUBJECTS`, neue Felder in `FIELDS` eintragen.
- `php artisan vc:import` (Daten aus der PHP-Version) hat seit dem Löschen der
  PHP-Version keine Quelle mehr und ist gesperrt (`VC_IMPORT_LOCKED`, Standard
  true). Die Daten in ipa.vcontrol.eu stammen aus dem letzten Import (Demodaten);
  zum Go-live wird Laravel direkt aus dem AppSheet-Export befüllt (docs/EVENTS.md).
- Filament-Code gegen `vendor/filament` auf dem Server prüfen, nicht aus dem
  Gedächtnis von Version 3 schreiben: Version 5 hat andere Namespaces
  (`Filament\Schemas\…`, `Filament\Actions\…`).
- Sicherung (`App\Support\Backups`): jede Nacht die Datenbank (`DatabaseDump`,
  reines PHP – im Web-PHP ist `proc_open` gesperrt), sonntags dazu
  `storage/app/private` als ZIP, per HTTP PUT in eine Nextcloud-Datei-Ablage
  (`VC_BACKUP_SHARE_URL`, `VC_BACKUP_SHARE_PASSWORD`; nur Hochladen – VenueControl
  kann dort nichts lesen oder löschen). Auslöser ist ein KAS-Cronjob auf
  `/cron/sicherung?key=…` (Adresse unter Verwaltung › System), von Hand
  `php artisan vc:backup [--dateien]`. Fehler gehen per Mail an die System-Admins,
  dazu ein Hinweis oben im Panel. Einspielen: `gunzip -c ….sql.gz | mysql …`, das
  ZIP nach `storage/app/private`.
- Überwachung durch das Cockpit (ops.bein.ws, eigenes Projekt): Es ruft
  `/_ops/status` mit dem Header `X-Ops-Token` (`OPS_STATUS_TOKEN` in der .env)
  ab – Version aus der Datei `REVISION`, die `scripts/deploy.sh` schreibt,
  Datenbankgröße, offene Migrationen, Stand der Sicherung, dazu Befunde und
  Zahlen aus `App\Support\SecurityStatus` (neue Admins, Rechteänderungen,
  ungenutzte Konten, Upload-Reste, offener Import) – und prüft täglich Pakete
  (`composer audit`), .env, Header und offene Pfade. Hohe Befunde mailt das
  Cockpit sofort; dieselbe Liste steht unter Verwaltung › System › Sicherheit.
  Die letzte Anmeldung je Konto (`users.last_login_at`) setzt ein Listener auf
  `Login`, ohne Audit-Eintrag.

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
  Uploads: erlaubte Endungen, verbotene Inhalte und die Grenze von 25 MB stehen
  in `App\Support\Uploads`. Livewire legt jede ausgewählte Datei sofort in seine
  Zwischenablage (`livewire-tmp`); `config/livewire.php` prüft deshalb schon dort,
  sonst landet alles bis zur Größengrenze auf dem Server. Upload-Felder für
  Dokumente mit `App\Filament\Forms\SafeFileUpload::allowedExtensions()` (der
  Auswahldialog zeigt nur diese Endungen), nicht mit `acceptedFileTypes()`.
  PHP-Grenze in `public/.user.ini`.
- Seitenbreite: Listen voll, Formulare/Dashboards mit `BoxedPage` (1400 px).
- Verfasser/Bearbeiter mit dauerhaftem Namen: Trait `App\Models\Concerns\StampsAuthor`
  (Spalten created_by, created_by_name, updated_by, updated_by_name).
- Unterschriften: Feld `App\Filament\Forms\SignaturePad` (Alpine im View, kein
  Build); Anzeige über `filament.events.signature-image`, nur geprüfte PNG-data:-URLs.
- Eigene Stile ohne Build-Schritt: `public/css/vcontrol.css`, eingebunden per
  Render-Hook im `AppPanelProvider`. Tailwind-Klassen in eigenen Blade-Dateien
  wirken nicht (Filament bringt nur die eigenen, fertig gebauten Klassen mit).
- Farben nur über das Farbschema (`App\Enums\ColorScheme`, Setting `color_scheme`,
  wählbar unter Verwaltung › System): Filament-Farben (`--primary-600` …) oder die
  Variablen des Schemas (`--vc-buchung`, `--vc-planung`, `--vc-durchfuehrung`,
  `--vc-accent`), keine neuen festen Hex-Werte für Markenfarben. Ein neues Schema
  ist ein neuer Fall im Enum (Farben, Variablen, bei Bedarf ein eigenes Stylesheet
  wie `public/css/scheme-lila-gruen.css` für die dunkle Kopfleiste). Einzelne
  Farben als ganze Skala angeben – Filament 5 nimmt aus einem Hex-Wert nur den
  Farbton (`ColorScheme::shadesLikeFilament3`). Logo und Favicons (`public/images/logo.svg`,
  `favicon.svg`, `public/favicon.ico`, `apple-touch-icon.png`, `logo-mail.png`)
  stammen aus `bk-avatar-kreis_schwarz.eps`; Seiten ohne Panel binden
  `partials.icons` ein.
- Handy: Tabellenspalten, die unter 768 px (oder 1024 px) nicht nötig sind,
  bekommen `->visibleFrom('md')` bzw. `('lg')` – auf dem Handy bleiben zwei bis
  drei Spalten. Formulare: Speichern-Leiste bleibt unten stehen, enge
  Innenabstände (vcontrol.css, Abschnitt „Handy und Tablet“).
- Nach jeder Änderung: Tests grün, `php artisan vc:check-access` gleich,
  `php artisan vc:smoke` fehlerfrei. Letzteres rendert als Admin jede Seite mit den
  echten Daten (je Resource die neuesten Datensätze und den ältesten) und findet,
  was nur mit Altwerten aus dem Import schiefgeht. `--only=/events` grenzt ein.

## Rechte

- Bereiche und Stufen: `App\Access\Area`, `App\Access\Level`; was ein Benutzer darf:
  `$user->access()`. Mehrere Rollen addieren sich, je Bereich gilt die höchste
  Stufe. Abgeschaltete Bereiche sind für alle zu, auch für (System-)Admins.
  Geschaltet werden sie unter Verwaltung › System (`App\Access\Modules`,
  Setting `disabled_areas`); bis dort zum ersten Mal gespeichert wird, gilt
  `VC_DISABLED_AREAS`. Benutzer, Rollen & Rechte und Halle bleiben immer an.
- **Module** (`App\Access\Module`): was eine Halle bucht – Bühnenplan, Saalplan,
  Gästeliste, Buchhaltung, Preislisten & Costing. Der System-Admin gibt sie unter Verwaltung › System
  frei (Setting `modules`; ohne Eintrag sind alle frei). Ohne Freigabe ist ein
  Modul für alle ausgeblendet, Gespeichertes bleibt. Jede Stelle, die ein Modul
  zeigt, fragt `Module::…->isEnabled()`: Reiter und Felder, Links und Knöpfe,
  Policies, Druck- und Download-Routen (404), `ExternSheet`; `vc:smoke`
  überspringt solche Seiten. Bringt ein Modul einen Bereich mit
  (`Module::areas()`, die Buchhaltung), ist der ohne Freigabe zu wie ein
  abgeschalteter und steht unter System nicht bei den Bereichen. Ein neues
  Modul: Fall im Enum, dann jede Stelle der Funktion prüfen, auch Dashboard,
  Fortschritt (`EventProgress`) und Daysheet.
- **Zwei Admin-Stufen:** Admin (`roles.is_super`) ist der Admin der Halle – alle
  Bereiche, Kalender, Benutzer und Verwaltung › Halle. System-Admin
  (`roles.is_system_admin`, nur der Betreiber) hat zusätzlich: Module
  freigeben und Bereiche schalten,
  Rollen & Rechte (sieht und ändert nur er, seit 12.10.2026 auch nicht mehr zum
  Ansehen für Admins; zugewiesen werden Rollen unter Benutzer – `vc:check-access`
  führt `/rollen` deshalb als bewusst strenger als die PHP-Version),
  IP-Adresse und Browser im Audit, System-Admin-Konten. System-Admins und ihre
  Rolle sieht nur, wer selbst System-Admin ist (Scope `visibleTo()` an `User` und
  `Role`, auch im Audit). Den ersten System-Admin einer Halle macht
  `php artisan vc:system-admin <E-Mail>`; danach vergeben ihn nur System-Admins.
  Hallen-Einstellungen gehören auf die Seite Halle, nicht in die `.env`.
- Jede Filament-Resource braucht eine Policy, am einfachsten als Unterklasse von
  `App\Policies\AreaPolicy`. Das Panel läuft mit `strictAuthorization()` – eine
  fehlende Policy-Methode ist ein Fehler, keine Freigabe.
- Neue Seiten in `App\Console\Commands\CheckAccess::PAGES` eintragen und
  `php artisan vc:check-access` laufen lassen: vergleicht je Rolle mit den
  Sollwerten der PHP-Version.
- Strenger als die PHP-Version: Admin-Konten und die Admin-Rolle ändern nur
  Admins, System-Admin-Konten nur System-Admins; niemand löscht sich selbst; der
  letzte aktive Admin bleibt Admin, der letzte System-Admin System-Admin.
  Allgemein: Niemand vergibt mehr Rechte, als er selbst hat (`Access::covers()`) –
  Konten mit mehr Rechten ändert nur, wer diese Rechte hat (UserPolicy), und beim
  Speichern prüft `AccountSafety` die neuen Rollen und Kalender-Rechte.
- Passwort ändert jeder selbst unter Profil (`App\Filament\Pages\Auth\EditProfile`,
  nur das Passwort); „Angemeldet bleiben“ gilt 30 Tage (`config/auth.php`).
  „Passwort vergessen?“ schickt einen Link per Mail, 10 Minuten gültig
  (`config/auth.php`). Die eigene Anfrageseite
  `App\Filament\Pages\Auth\RequestPasswordReset` antwortet auf jede Adresse
  gleich – auch unbekannte und gesperrte, die keine Mail bekommen, und wenn der
  Mailversand scheitert (steht dann im Log) –, damit sich nicht ausprobieren
  lässt, wer ein Konto hat.
- **Externe (Recht „Events (extern)“) sind im selben Panel.** Sie sehen nur
  „Meine Events“ (`ExternEventResource` mit eigenem Modell `ExternEvent` und
  `ExternEventPolicy`) – alles andere sperren Policies und `canAccess()`. Jede
  neue Seite braucht deshalb eine echte Rechteprüfung, nie „true“. Was Externe
  und Daysheets von einem Event zeigen, steht nur in `App\Support\ExternSheet`.
- Das Audit zeigt jedem nur Einträge aus Bereichen, die er auch sonst lesen darf
  (`AuditPresenter::SUBJECT_AREAS`) – neue Tabellen dort eintragen, sonst sehen
  sie nur Admins.
- Sicherheits-Header und HTTPS-Zwang: `App\Http\Middleware\SecurityHeaders`.
  Dateien nie mit dem gespeicherten MIME-Typ inline ausliefern (siehe
  `EventFile::inlineType()`), FileUpload-Felder mit `->preventFilePathTampering()`.
- Die Server-CLI ist PHP 8.3 (`php`), daneben gibt es `php85`. Code muss unter beiden
  laufen.
- Oberfläche, Texte, Kommentare und Commit-Nachrichten auf Deutsch.
