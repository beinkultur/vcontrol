# Mehrere Venues je Installation („schlank nach Nutzung“) – Design

Stand 17.11.2026. Für ce.vcontrol.eu (Campus Events: LKH Arena und
Ritterakademie). Grundlage ist die Bestandsaufnahme vom 17.11.2026 (Notizordner
der Sitzung: `ce-mehrere-venues-inventar.md`; Datei- und Zeilenangaben dort).

## Warum

Campus Events betreibt mehrere Venues und will sie in **einer** Instanz
verwalten: gemeinsame Leute, ein Kalender, eine Event-Liste. Heute gilt in
VenueControl „eine Installation = eine Venue“. Name, Logo, Antwortadresse,
Daysheet-Texte, Saalpläne und Feldoptionen gelten für die ganze Installation.
Die Ritterakademie kann deshalb nicht mit eigener Identität und eigenen
Saalplänen neben der LKH Arena laufen, und der Advancing-Katalog „Kultur“ (mit
LKH-Preisen und LKH-Hinweisen) stünde auch für Events der Ritterakademie zur
Wahl.

## Entscheidungen des Nutzers (17.11.2026)

| Frage | Entscheidung |
|---|---|
| Trennung | **A – Venue als Merkmal.** Alle sehen beide Venues und filtern nach Venue. Keine Rechte je Venue. |
| Je Venue | Name, Logo, Adresse, Antwortadresse; Daysheet-Texte und -Empfänger; Advancing-Kataloge; Saalpläne; Bereiche und Bestuhlung; (Räume, später Bühne, Zähler, Codes) |
| Gemeinsam | Benutzer, Rollen, Rechte; Veranstalter, Kontakte, Gewerke; VA-Status, VA-Kategorien, Ticketing, Positionen, Datei-Tags; **ein** Nummernkreis für VA-Nr. und VA-ID; Module und abgeschaltete Bereiche je Installation |
| Anzeige | Event-Liste mit Spalte und Filter „Venue“. Kalender: eine Ebene „Events“, je Venue ein Chip, Kürzel im Titel („LKH · …“). Venue Pflicht beim Anlegen, bei nur einer Venue vorgewählt und unsichtbar. |
| Anfragen | Venue an der Anfrage, Belegung und Rang je Venue – **aber** bei CE abgeschaltet, deshalb in diesem Weg nicht gebaut, sondern gesperrt (siehe „Sperre“) |
| Umfang | **Weg 2 „schlank nach Nutzung“**: venue-fähig wird nur, was CE eingeschaltet hat; alles andere bleibt gemeinsam und ist in Installationen mit mehreren Venues gesperrt, bis es venue-fähig ist |
| Weitere Venues | vorerst keine |
| Termin | kein fester Termin – Reihenfolge wie unten (Vorstufe zuerst, dann 1–7 gesamt) |

## Ziele

1. Eine zweite Venue anlegen (System-Admin) und Events, Saalpläne, Bereiche,
   Bestuhlung und Advancing-Kataloge einer Venue zuordnen.
2. Alles, was Externe zu einem Event sehen oder bekommen (Daysheet,
   Advancing-Seite, Advancing- und Daysheet-Mail, Abgabe-Mail, „Meine
   Events“), trägt Name, Logo und Antwortadresse **der Venue des Events**.
3. Event-Liste und Kalender zeigen und filtern die Venue.
4. **Für Installationen mit einer Venue (ipa, demo) ändert sich sichtbar
   nichts.** Jede sichtbare Neuerung hängt an `Venue::isMulti()`.
5. Vorstufe: Durchführung › Betrieb (mit dem IPA-Durchführungs-Check) wird
   abschaltbar – das hilft CE sofort (vergangene Events bleiben sonst unter
   „Zukünftige“ stehen, bis die IPA-Punkte ausgefüllt sind).

## Nicht in diesem Weg (eigene Pakete, wenn CE den Bereich einschaltet)

Bühne/Bühnenplan, Zähler, Räume, Preislisten & Costing, Buchhaltung und
Eingangsrechnungen, Gästeliste, Codes Tageszugang, Inventar und Artikel,
Show-Checklisten, Schäden, Anfragen/Belegung, Kalendereinträge mit Venue,
Feed je Venue, pflegbare Rollen am Event, Planungs-Checkliste je Venue,
Module je Venue, Rechte je Venue.

## Anti-Ziele

- Kein „halb venue-fähiger“ Bereich: Was nicht umgebaut ist, lässt sich in
  einer Installation mit mehreren Venues nicht einschalten (Sperre).
- Keine sichtbare Änderung für ipa und demo, auch nicht im Verlauf der
  Nummern, Feeds und Abos (Feed-Adresse und UIDs bleiben gleich).
- Kein Saalplan, Bereich oder Katalog einer fremden Venue am Event – auch
  nicht per manipulierter Anfrage (serverseitig prüfen).
- Werte, an denen Code hängt (VA-Status „storniert“/„fraglich“,
  „Endabrechnung gestellt“, VA-Kategorie „Konzert“, Positionen VL/VfV), werden
  nie je Venue getrennt.

## Ansätze (betrachtet)

1. **Vollständig** (alle Bereiche venue-fähig, 120–160 h): verworfen – baut
   Bereiche um, die CE nicht nutzt.
2. **Schlank nach Nutzung** (gewählt): nur die genutzten Teile; Rest gesperrt.
3. **Zweite Instanz** für die Ritterakademie (6–10 h): verworfen – keine
   gemeinsame Liste/Kalender, doppelte Pflege, späteres Zusammenlegen teuer.

Technische Grundentscheidung (Entscheidung des Bauenden): **Tabelle `venues`
für die Identität, Tabelle `venue_settings` für Werte je Venue mit Vererbung**
Venue → Installation (`settings`) → Konstante. So bleibt bei einer Venue alles
in `settings`, wo es heute steht; eine neue Venue erbt die Installationswerte,
bis sie eigene setzt. Verworfen: alles in Spalten an `venues` (jede künftige
Einstellung bräuchte eine Migration) und `settings` um eine `venue_id`
erweitern (Primärschlüssel ist `key`, zu tiefer Eingriff).

## Datenmodell

- **`venues`**: `id`, `name` (120), `short_name` (12, für Liste/Kalender,
  eindeutig), `logo_path` null, `mail_reply_to` null, `address` null (Text,
  für Daysheet/Advancing-Kopf optional), `sort_order`, `is_active`, Zeitstempel.
  Modell `Venue` mit `Venue::isMulti()` (mehr als eine aktive Venue, je Anfrage
  zwischengespeichert wie `Modules`), `Venue::default()` (die erste aktive) und
  `Venue::defaultId()`.
- **`venue_settings`**: `venue_id`, `key`, `value`, Unique (venue_id, key).
  Zugriff `VenueSettings::for(Venue)->get(key, default)` mit Vererbung auf
  `Setting::lookup`. In diesem Weg nur: `daysheet_to`, `daysheet_subject`,
  `daysheet_text`. Weitere Schlüssel kommen mit ihren Paketen.
- **`events.venue_id`**: NOT NULL, Fremdschlüssel. Migration in drei Schritten
  (nullable → mit Venue 1 füllen → NOT NULL). Default-Hook im Modell
  (`venue_id ??= Venue::defaultId()`), damit `Inquiries::confirm`, DemoSeeder,
  ImportLegacy und bestehende Tests ohne Venue weiterlaufen.
- **`seating_plans.venue_id`**: NOT NULL, Bestand an Venue 1; Name eindeutig je
  Venue.
- **`field_options.venue_id`**: nullable (NULL = für alle Venues). Je Venue
  pflegbar nur Felder mit `OptionField::perVenue()`: **Bereiche** und
  **Bestuhlung**. Alle anderen Felder bleiben gemeinsam (Wert-Schutz, Test).
- **`advancing_templates.venue_id`**: nullable (NULL = für alle). Unique
  (venue_id, name). Import mit Feld `venue` (Kürzel) in der JSON-Datei oder
  `--venue=`. Der LKH-Katalog bekommt bei CE die Venue LKH Arena.
- **Migration von Venue 1** (`venues` neu): `name` aus `venue_name` (sonst
  `config('app.name')`), `short_name` aus den ersten Buchstaben (änderbar),
  `logo_path` aus `venue_logo`, `mail_reply_to` aus `mail_reply_to`. Die alten
  Settings bleiben stehen (Rückfall, Rückweg); gelesen wird künftig über
  `Venue`. Mehrfach lauffähig ohne Schaden.
- **Neue Installationseinstellung** `operator_name` (Betreiber, z. B. „Campus
  Events“) in `settings`; leer = Name von Venue 1.

## Identität: wer steht wo

| Ausgabe | eine Venue (ipa, demo) | mehrere Venues (CE) |
|---|---|---|
| Panel-Kopf, Anmeldung, Wartungsseite 503, Zugangsdaten- und Passwort-Mail, Sicherungs-Mail, persönlicher Kalender | Venue 1 (wie heute) | Betreiber (`operator_name`, VenueControl-Logo bzw. Logo von Venue 1 – Entscheidung des Bauenden: Logo von Venue 1 nur bei einer Venue) |
| Daysheet (Seite, Mail, Vorschau), Advancing (Seite, Versand-Mail, Abgabe-Mail, 410-Seite), „Meine Events“, Event-Kalender-Feed-Einträge | Venue 1 | Venue des Events (Name, Logo, Antwortadresse; Platzhalter `{venue}`) |
| Absenderadresse | `.env` (`no-reply@…`) | unverändert `.env`; Absendername = Venue des Events bzw. Betreiber |

Ein Helfer `VenueIdentity` fasst die 13 heutigen Lesestellen von
`Setting::VENUE_NAME` zusammen: `VenueIdentity::name(?Venue)` und
`VenueIdentity::installation()`. `MailIdentity::from(?Venue)` /
`replyTo(?Venue)`, `VenueLogo` mit Venue-Parameter (Ablage `venue/{id}/`,
neue Route `/venues/{venue}/logo`, `/venue/logo` und `/halle/logo` leiten auf
Venue 1). Die Wartungsseite muss auch ohne Tabelle `venues` rendern (beim
Einspielen vor der Migration).

## Verwaltung

- **Eine Venue:** Verwaltung › Venue bleibt **unverändert** (bearbeitet intern
  Venue 1 bzw. die Installation).
- **Mehrere Venues:** Neuer Menüpunkt Verwaltung › Venues (Liste, Bearbeiten):
  Name, Kürzel, Logo, Antwortadresse, Adresse, Daysheet-Empfänger/-Betreff/-Text
  (leer = Vorgabe der Installation), Reihenfolge, aktiv. Verwaltung › Venue
  heißt dann „Installation“ und behält, was je Installation gilt (Bühne,
  Costing, Kalender-Feed, Schadensmeldungen, `operator_name`); dort ein Hinweis
  auf Verwaltung › Venues.
- **Anlegen und Archivieren** von Venues nur der System-Admin (preisrelevant),
  „Anlegen als Kopie von …“ übernimmt Daysheet-Texte. Löschen nur ohne Events.
  Die **erste zweite Venue** schaltet `isMulti` ein.
- `VenuePolicy` (AreaPolicy, Bereich Stammdaten; anlegen/archivieren nur
  System-Admin), `CheckAccess::PAGES` um `/venues`, `vc:smoke` mit Stichproben
  je Venue.

## Events, Liste, Kalender

- **EventForm (Buchung › Daten):** Pflichtfeld Venue (live) – bei einer Venue
  verborgen und vorbelegt, bei mehreren vorbelegt aus dem Filter der Liste
  (`?venue=`). Saalplan-, Bereichs- und Bestuhlungsauswahl hängen an der Venue
  (Helfer `venueId(Get, ?Event)`; in Abschnitten mit `relationship()`
  `$get('../venue_id')`). Serverseitig: Saalplan muss zur Venue gehören,
  Bereiche/Bestuhlung zu den Optionen der Venue (oder „alle“).
- **Venue wechseln:** erlaubt, solange kein Saalplan (Standard) und kein
  Advancing am Event hängen; sonst gesperrt mit Hinweis. Bereiche/Bestuhlung,
  die es in der neuen Venue nicht gibt, werden beim Speichern gemeldet
  (Formularfehler), nicht still gelöscht.
- **Kopf des Workspace, globale Suche, Übersicht:** Kürzel der Venue (nur bei
  mehreren).
- **Event-Liste:** Spalte (Badge mit Kürzel) und Filter „Venue“, `with('venue')`.
- **Kalender:** zweite Chip-Leiste „Venue“ (Adresse `venue[]`), Vorgabe alle;
  Events mit Kürzel im Titel („LKH · Titel“). Dienste aus dem Personal ebenso
  mit Kürzel. Externer Kalender: Kürzel, sonst unverändert.
- **ICS-Feed** `/kalender/events.ics`: ein Feed wie bisher (Adresse, Schlüssel
  und UIDs unverändert), Titel mit Kürzel und `LOCATION` = Venue-Name – nur bei
  mehreren Venues.
- **„Meine Events“ (Externe):** Spalte und Untertitel mit der Venue (nur bei
  mehreren).
- **Nummern:** ein gemeinsamer Nummernkreis, keine Änderung (Test sichert ab).

## Advancing

- Kataloge mit Venue (oder für alle). „Advancing starten“ bietet nur Kataloge
  der Event-Venue und „für alle“ an und prüft das serverseitig.
- Seite, Versand-Mail und Abgabe-Mail mit Identität der Event-Venue; die
  Abgabe-Mail geht an die Antwortadresse der Venue (Rückfall: Installation).
- Bei CE: Migration ordnet „Kultur“ der LKH Arena zu (per Befehl nach dem
  Einspielen, nicht in der Migration – die kennt die Venue-Namen nicht).

## Vorstufe: Durchführung › Betrieb abschaltbar

Neues Modul `Module::Operations` („Betrieb“): Reiter Durchführung › Betrieb
(Räume, Bus-Strom, Zählerstände je Event, Check), `ExecutionCheck`
(vergangene unvollständige Events unter „Zukünftige“, Tooltip, Fortschritt
der Durchführung), Spalten/Abschnitte dazu im Buchhaltungs-Dashboard. Ohne
Freigabe: alles aus, vergangene Events verhalten sich wie vollständig.
Bestehende Installationen: ipa hat Module gespeichert → **Migration ergänzt
`betrieb` in gespeicherten Modul-Listen**, damit sich bei ipa nichts ändert
(anders als beim Advancing-Modul, das dort aus bleiben sollte). CE: danach
unter Verwaltung › System abschalten.

## Sperre für nicht venue-fähige Teile

`Venue::isMulti()` → folgende Module und Bereiche gelten als **nicht frei bzw.
abgeschaltet**, egal was gespeichert ist, und sind unter Verwaltung › System
ausgegraut mit Hinweis „noch nicht für mehrere Venues verfügbar“:
Module Bühnenplan, Gästeliste, Buchhaltung, Preislisten & Costing, Betrieb;
Bereiche Anfragen, Zählerstände, Codes Tageszugang, Event-Operationen,
Protokolle, Inventar. Eine Liste an einer Stelle (`Modules::NOT_VENUE_READY`),
die jedes spätere Paket verkleinert. Test: eine Venue → nichts gesperrt; zwei
Venues → gesperrt, gespeicherte Rechte bleiben erhalten.

## Prüfen

- Regressionstest „eine Venue wie heute“: Panel-Kopf, Verwaltung › Venue,
  Daysheet, Advancing, Mails, Feed, Liste, Kalender zeigen exakt das Bisherige;
  keine Venue-Spalte/-Filter/-Chips.
- Fixture mit zwei Venues; je Bereich ein Test, dass eine fremde ID (Saalplan,
  Katalog, Feldoption) serverseitig abgelehnt wird.
- Identität: Daysheet/Advancing/Mails einer Ritterakademie-Veranstaltung tragen
  deren Name, Logo, Antwortadresse.
- Migration: mit Bestand (Settings gesetzt/nicht gesetzt), zweimal laufen
  lassen; Wartungsseite ohne Tabelle `venues`.
- Sperre, Betrieb-Modul (ipa behält Betrieb, ExecutionCheck aus ohne Modul).
- Die ~60 Stellen in Tests, die `Setting::put(VENUE_NAME …)` nutzen, laufen
  weiter (Rückfall) oder werden umgestellt.
- Testkopie mit eigener SQLite-DB (nie die ipa-`.env` mit `TEST_DB_*`),
  PHP 8.3 und 8.5, `vc:check-access`, `vc:smoke` (Stichproben je Venue),
  Sicherung vor der Migration auf allen Installationen, die eingespielt werden.

## Aufwand und Reihenfolge

| Teil | h |
|---|---|
| 0. Vorstufe: `VenueIdentity` zusammenziehen (ohne sichtbare Änderung), Modul Betrieb inkl. ExecutionCheck und Migration für ipa | 6–9 |
| 1. Grundlage: `venues`, `venue_settings`, Trait/Default-Hook, Venue-Feld-Baustein, `events.venue_id`, Migration von Venue 1 | 11–14 |
| 2. Identität und Verwaltung: Betreiber, Panel/Anmeldung/503, Verwaltung › Venues, Logo je Venue, Audit | 16–20 |
| 3. Ausgaben: MailIdentity, Mail-Logo, Daysheet (fill/defaults/Seite/Mail), Advancing (Seite/Mails), Meine Events, Konto-Mails mit Betreiber | 10–13 |
| 4. Events/Liste/Kalender/Feed | 12–16 |
| 5. Stammdaten: Saalpläne, Bereiche/Bestuhlung je Venue, abhängige Auswahl und Venue-Wechsel im EventForm, Advancing-Kataloge, Wert-Schutz | 18–23 |
| 6. Sperre nicht venue-fähiger Teile | 3–4 |
| 7. Tests übergreifend, Smoke/Check-Access, Testkopie, Doku, Gesamtprüfung | 8–11 |
| **Summe** | **84–110 h** |

Höher als die genannten 75–95 h, weil Vorstufe (Betrieb abschaltbar), Sperre
und die übergreifenden Tests jetzt einzeln gezählt sind.

Lieferungen: **0** zuerst und einzeln einspielbar (hilft CE sofort, ipa
unverändert). Dann **1–7** in einem Zweig; eingespielt erst komplett. Erst
danach legt der System-Admin die Ritterakademie an.

## Einspielen

ipa: nur nach „deploy“, Sicherung vorher, Wartungsmodus, Migration (demo bleibt außen vor);
danach Regressionsprüfung (Seiten, Feed-Adresse, VA-ID des nächsten Events).
ce: zusätzlich `operator_name` „Campus Events“, Kürzel „LKH“, Katalog „Kultur“
an LKH Arena, Betrieb abschalten, dann Ritterakademie anlegen (System-Admin).

## Klärungen nach der Builder-Prüfung (17.11.2026, Entscheidungen des Bauenden)

- **`isMulti()`** = mehr als **eine Venue überhaupt** (auch archivierte zählen).
  Archivieren nimmt eine Venue nur aus der Auswahl für neue Events und
  Stammdaten; die Installation fällt nie in den Ein-Venue-Modus zurück, solange
  es zwei Venues gibt. Ausgaben lesen immer die Venue des Events.
- **Die zweite Venue anlegen:** nur der System-Admin, im Ein-Venue-Modus über
  eine Aktion auf Verwaltung › Venue („Weitere Venue anlegen“, für alle anderen
  unsichtbar). Vorher ein Bestätigungsdialog mit der Liste der Module und
  Bereiche, die dann gesperrt werden. Kein zusätzlicher Schalter in der `.env`.
- **Daysheet-Werte:** `daysheet_to` (Empfänger) wird **nicht** vererbt – jede
  Venue hat eigene Empfänger. Betreff und Text erben von der Installation
  (Vorgabe für alle Venues, im Mehr-Venue-Modus auf der Seite „Installation“
  pflegbar). Beim Anlegen der zweiten Venue bekommt Venue 1 die bisherigen
  Empfänger als eigenen Wert (`venue_settings`). Antwortadresse: Spalte je
  Venue; leer → Antwortadresse der Installation (`settings.mail_reply_to`).
- **Feldoptionen bei der Migration:** Bereiche → Venue 1 (es sind die Bereiche
  der bisherigen Venue). Bestuhlung → **für alle** (NULL), neue Werte je Venue
  möglich. **„bestuhlt“ ist ein geschützter Wert** (gemeinsam, nicht je Venue
  löschbar/umbenennbar), wie die anderen Werte, an denen Code hängt; Test.
- **Modul Betrieb bringt den Bereich Räume mit** (`Module::areas()` =
  [AdminRaeume], wie Buchhaltung) – Räume werden nur unter Betrieb genutzt.
  Stammdaten › Zähler ist nur erreichbar, wenn Betrieb oder Zählerstände frei
  sind. Damit sind Räume und Zähler bei mehreren Venues mitgesperrt.
  **Wird in Lieferung 0 nachgezogen.**
- **Venue eines Events wechseln:** immer erlaubt (auch mit Advancing – das ist
  eine Kopie und liest die Identität der Event-Venue). Ein Standard-Saalplan
  einer anderen Venue wird dabei entfernt (Hinweis), Bereiche/Bestuhlung, die es
  in der neuen Venue nicht gibt, melden einen Formularfehler. Damit lassen sich
  vorab als LKH angelegte Ritterakademie-Events umhängen; kein Sonderbefehl.
- **Testdaten mit zwei Venues:** ein Seeder nur für Tests und die Testkopie
  (zweite erfundene Venue mit Logo, Saalplan, Katalog, Daysheet-Texten); keine
  Kopie der ce-Datenbank (echte Personendaten).
- **Rückweg:** über die Sicherung (Datenbank + Dateien) je Installation, keine
  doppelte Schreibweise für alten Code. ipa.vcontrol.eu ist ein ruhender Pilot
  mit Demodaten. **Vor dem Einspielen von 1–7 auf ce muss dort die nächtliche
  Sicherung laufen** (Nextcloud-Datei-Ablage + KAS-Cronjob, Nutzer).
- **ipa-control.de** (IPA-Control, eigenes Repo) bleibt unberührt.
- **demo.vcontrol.eu bleibt außen vor** (läuft seit 29.10.2026 mit IPA-Control-Code als
  EIC-Testumgebung). Geprüft wird „eine Venue wie heute“ auf ipa.vcontrol.eu und in der
  Testkopie. (Empfehlung an den Nutzer am 17.11.2026, nicht widersprochen.)

## Dem Bauenden überlassen

Klassen- und Methodennamen im Detail, Gestaltung der Venue-Liste, Text der
Hinweise, Kürzel-Vorschlag bei der Migration, wie die Wartungsseite ohne
Tabelle zurückfällt, Reihenfolge der Commits innerhalb von 1–7.
