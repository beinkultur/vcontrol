# Events – Entwurf für die Laravel-Version

Stand 30.09.2026. Grundlage ist das Datenmodell der PHP-Version (vc.bein.ws,
239 Events). Entscheidungen, die noch bestätigt werden müssen, sind mit
**[offen]** markiert; bis dahin gilt die jeweils empfohlene Variante.

## Grundsätze

- **IDs bleiben erhalten.** `events.id` = `vc_events.id`. Der Import ist wieder-
  holbar und Verweise (Gäste, Schäden, Bestellscheine …) passen ohne Umrechnung.
- **Komma-Text wird Liste.** Die PHP-Version speichert Mehrfachauswahlen als
  „Innenraum , Oberrang A-D“. Hier werden daraus JSON-Listen: Bereiche,
  Bestuhlung, FIBU-Status (z. B. „1. Rate gezahlt“ und „2. Rate gezahlt“
  gleichzeitig). Suchen nach einem Wert laufen dann über `whereJsonContains`
  statt `LIKE`.
- **Zusatztabellen 1:1 bleiben, wo sie einen eigenen Bereich haben.** Filament
  kann Formularabschnitte direkt an eine 1:1-Beziehung binden. Getrennt
  bleiben, was eigene Rechte oder eine eigene Ansicht hat: Finanzen
  (Buchhaltung), Bühne (Bühnenplan), Zeiten, Betrieb, PR, Checkliste.
- **Altlasten aus AppSheet fallen weg:** `legacy_key`, `calendar_id` (IDs eines
  Google-Kalenders), `pl` (Projektleitung – bei allen 239 Events leer).
  **[offen]**

## Tabellen

| Neu | Quelle | Inhalt |
|---|---|---|
| `events` | `vc_events` | Titel, VA-NR/VA-ID, Veranstalter, Status, Kategorie 1/2, Beginn/Ende, PAX erwartet/abgerechnet, Bereiche (Liste), Bestuhlung (Liste), Ticketing, WLAN, Beschreibung, Buchungsnotizen, Ansprechpartner vor Ort, Abschluss-Schalter |
| `event_finances` | `vc_event_finance` | Vertragsstatus, FIBU-Status (Liste), Preisliste, Miete, Rechnungsnummern (Liste statt drei Spalten), Abrechnung abgeschlossen |
| `event_schedules` | `vc_event_schedules` | Einlass, Beginn, Ende, Curfew, Get-in, Load-in, Load-out, VIP-Einlass |
| `event_stages` | `vc_event_stage` | Bühnenmaße, Seitenbühnen, Podeste, Treppen, Rückwand, Notizen |
| `event_operations` | `vc_event_operations` | Bus-Strom (Anschlüsse), Haus-Delay, Backstages/Büros und Zähler-Verweise aus AppSheet; Stromzähler und Verbrauch bis 15.10.2026, seitdem `meter_readings` |
| `event_pr` | `vc_event_pr` | PR-Datum, PR-Status |
| `event_checklists` | `vc_event_checklist` | 15 Punkte ja/nein/entfällt |
| `event_room` | `vc_event_rooms` | Raumbelegung mit Art (Backstage/Büro) |
| `event_services` | `vc_event_trades` | Leistungen je Event (4.465 Zeilen): Leistungscode, Gewerk oder freier Anbieter, verantwortlich Venue/Veranstalter, Notiz |
| `event_service_groups` | `vc_event_trade_active`, `vc_event_trade_groups` | welche Leistungsgruppen aktiv sind |
| `event_assignments` | `vc_event_role_assignments` | Rollen am Event (VL, VfV …) an Benutzer, Kontakt oder Gewerk, mit Zeiten (Kontakte statt Mitarbeiter seit 14.10.2026) |
| `contacts` | `vc_employees` (bis 14.10.2026 `employees`) | Kontakte ohne Anmeldung: Person oder Funktion („Hallenmeister“), Kürzel, Telefon, E-Mail, Positionen, Notiz, archiviert |
| `event_incoming_invoices` | `vc_event_incoming_invoices` | die sechs erwarteten Eingangsrechnungen |
| `seating_plans` | neu (12.10.2026) | Standard-Saalpläne der Venue: PDF, Name, genutzte Bereiche, Bestuhlung, aktiv |
| `meters` | neu (15.10.2026) | Zähler der Venue: Name, Einheit, Zählernummer, Standort, je Event ablesen, aktiv, Reihenfolge |
| `meter_readings` | neu (15.10.2026), Strom aus `event_operations` | Stände: Zähler, Event mit Anfang/Ende oder frei, Zeitpunkt, Stand (3 Nachkommastellen), Zählerwechsel, Notiz |

Später, mit seinem Modul: Ablaufplan (`vc_event_attachments`).

## Go-live: Daten aus AppSheet

Zum Go-live wird Laravel direkt aus dem CSV-Export der AppSheet-App befüllt,
**nicht** aus der PHP-Version (Vorgabe vom 01.10.2026). `vc:import` spiegelt
die PHP-Datenbank nur, um während der Entwicklung mit echten Daten zu arbeiten,
und überschreibt dabei alles, was in Laravel eingegeben wurde.

Für den noch zu bauenden AppSheet-Import festgelegt:
- Bühne wie `src/AppSheetStage.php` der PHP-Version: BÜHNE-SONSTIGE als Anzahl →
  „Sonstige“ (`extra_platforms`, zählt mit; Text → `other_info`), BÜHNE-ANM →
  „Anmerkungen Bühne“ (`stage_notes`), Podest-Summe aus den Maßen berechnen
  (Standard-Rollipodest zählt mit, wie beim Speichern des Formulars).
- Offen: Die Zähler-Verweise der Events (`power_start_ref`/`power_end_ref`, auf
  ipa 23 bzw. 34 Events) zeigen auf Ablesungen in AppSheet. Sie gehören als
  Stand Anfang/Ende des Zählers „Strom“ nach `meter_readings`
  (`App\Support\MeterReadings::saveForEvent`), freie Ablesungen ohne Event als
  Stände ohne Event.

## Stand der Übernahme (01.10.2026)

Übernommen: Kern, alle sechs 1:1-Tabellen, Leistungen, Leistungsgruppen,
Rollen, Raumbelegung, Eingangsrechnungen, Gästeliste, Notizen, Dateien,
Bestellscheine, Übergabeprotokolle, Schäden mit Fotos.

Bedienbar:
- **Event-Liste** wie in der PHP-Version: Spalten Datum, Status, Veranstaltung
  (rotes ! bei Finanz-Warnung), Veranstalter, VA-Kat., PL, PAX, Bestuhlung (🪑),
  Bühne, VA-ID, Fortschritt Buchung/Planung; nach Monaten gruppiert, schmale
  Zeilen, fraglich/abgesagt/vergangen farbig markiert. Filter Status, Zeitraum,
  Jahr, Veranstalter (voreingestellt offen und zukünftig). Ein Klick öffnet
  das Event – zum Bearbeiten, wer darf, sonst nur lesend.
- **Workspace** wie in der PHP-Version: Kopf mit Datum · Veranstalter · VA-ID ·
  Status, Reiter Übersicht | Buchung | Planung | Durchführung mit Fortschritt.
  Die Übersicht ist das Dashboard (Finanz-Warnung, Phasen-Karten mit
  Stichpunkten, Stammdaten, Planungsbereiche, Notizen). Unterbereiche als
  eigene Reiter: Buchung › Daten | Buchhaltung | PR, Planung › Zeiten |
  Checkliste | Bühne | Personal | Gewerke | Gästeliste | Dateien | Sonstiges,
  Durchführung › Betrieb | Übergabeprotokolle | Bestellscheine | Checklisten |
  Schäden. Die Reiter stehen in der URL
  (`?phase=planung&bereich=buehne`). Leserollen sehen denselben Workspace nur
  lesend – ohne Buchhaltung (fehlt ganz, nicht nur ausgeblendet) und ohne
  WLAN-Passwort, wie in der PHP-Version. Neu anlegen: nur die Daten, danach
  geht es in den Workspace. Die Checkliste zeigt die PHP-Version nur als
  Platzhalter, obwohl die Daten existieren – hier ist sie bearbeitbar, mit
  der Überschrift „Sind folgende Positionen geplant?“. Seit 12.10.2026 ohne
  „Briefing vollständig“ (Spalte bleibt mit Altwerten); Merch-Fee und
  „Merch-Fee eingesammelt“ stehen unter Durchführung › Betrieb › Check.
- Gästeliste mit Druckansicht für den Einlass (A4, nach Name sortiert, Summe,
  Spalte zum Abhaken).
- **Durchführung › Betrieb** (01.10.2026): Räume einmal je Raum mit Backstage /
  neutral / Büro; Bus-Strom als Anzahl der Anschlüsse (0–5, einzeln
  abgerechnet, Spalte in der Buchhaltung); Zählerstände Anfang und Ende je
  Zähler „je Event“ (bis 15.10.2026 nur der Stromzähler), der Verbrauch wird
  daraus berechnet (kein Eingabefeld mehr); „Check“ wie in
  der PHP-Version: Sonderreinigung, Haus-Delay, Miete Elektro-Ameise, Haus-Rig
  ab 7 Uhr, Sold-Out-Award, dazu PAX abgerechnet und (seit 12.10.2026, vorher
  Planung › Checkliste) Merch-Fee und „Merch-Fee eingesammelt“ (ja/nein/
  entfällt). Die Zählerstände gibt es nur hier, nicht in der PHP-Version.
- **Durchführung › Übergabeprotokolle** wie in der PHP-Version: Inventar an
  einen Empfänger (mit dessen Unterschrift), „Zurückerhalten“, Unterschrift
  später ergänzen. **Durchführung › Bestellscheine**: „Bestellt von“, Artikel
  mit Menge (Preis und Summe aus der Artikelliste, bleiben auf dem Schein
  stehen), Unterschrift, „abgerechnet“ setzt die Buchhaltung. Beides nicht
  löschbar wie dort. Übersichten unter „Protokolle“ (Recht „Protokolle“, für
  alle Rollen gleich der PHP-Version geprüft). Artikel unter Stammdaten (die
  PHP-Version hat dafür keine Seite). Unterschrift: eigenes Feld
  `App\Filament\Forms\SignaturePad` (PNG als data:-URL wie dort).
- **Durchführung › Checklisten** (01.10.2026) nach der „EventsCheckliste“ aus
  AppSheet – die PHP-Version hat dafür nur einen Platzhalter, Altdaten gibt es
  keine: Material (Emergency Case, Barriercase, Produktionscase vollständig?,
  Schlagschrauber zugänglich?, Barriers zurück?, Geländerschrauben, Busstrom
  abgeschaltet?, Backstages gecheckt?) und Kleinteile (Unterlegscheiben
  klein/groß, Schrauben kurz/lang, Geländerschrauben), je Ja/Nein mit
  Anmerkung; House-Rep. (vorbelegt mit dem Benutzer) und Prom.-Rep.
  (vorbelegt mit dem Ansprechpartner vor Ort) mit Unterschrift, Bemerkungen.
  Mehrere je Event, löschbar mit Recht „Event-Operationen“. Prüfpunkte in
  `App\Support\ShowChecklist`.
- **Durchführung › Schäden** wie in der PHP-Version: Zeitpunkt, Beschreibung,
  bis 20 Fotos (je 25 MB), „behoben“ setzen Event-Operationen oder die
  Buchhaltung; nicht löschbar. Übersicht unter Protokolle › Schäden, dort auch
  allgemeine Schäden ohne Event. Neue Schäden gehen per Mail an die
  Empfänger unter Verwaltung › Venue › E-Mail, sonst an alle aktiven Benutzer
  mit der Rolle „hausmeister“. Fotos liegen nicht öffentlich, Auslieferung über
  `/schaeden/{id}/foto/{n}` mit Rechteprüfung; `vc:import` übernimmt die
  Schäden samt Fotos (50 Schäden, 1 Foto, bytegleich geprüft).
- **Event-Operationen in der Ansicht:** Übergabe, Bestellscheine, Checklisten
  und Schäden sind auch in der Lese-Ansicht des Events bearbeitbar, wenn die
  Rolle „Event-Operationen“ bearbeiten darf (etwa der Hausmeister: Events
  lesen, Operationen bearbeiten) – wie in der PHP-Version. Wer die
  Bearbeiten-Seite eines Events ohne Schreibrecht aufruft (z. B. über den Link
  in der Schadensmail), landet in der Ansicht, Phase und Bereich bleiben.
- **Kalender** (`/kalender`, Recht „Kalender“, 13.10.2026) wie in der
  PHP-Version: Monat, Woche, Tag, 4 Wochen; die Ebenen aus Verwaltung ›
  Kalender als Chips in ihrer Farbe zum Ein- und Ausblenden, Auswahl in der
  Adresse (`?view=month&date=2026-11-01&cal[]=events`); ohne Angabe die
  Standard-Ansicht aus dem Profil. Reihenfolge der Ebenen überall: Events,
  Anfragen, Persönlich, danach alphabetisch (Vorgabe vom 13.10.2026, das
  Sortieren von Hand unter Verwaltung › Kalender entfällt). Inhalt je Ebene
  (`App\Support\CalendarItems`): **Events** (alle; fragliche blass, stornierte
  durchgestrichen – in der PHP-Version ohne Kennzeichnung), **Anfragen** (offene,
  je Wunschtermin mit Rang „1️⃣ Titel (1. Opt.)“; Tage, die ein bestätigtes Event
  belegt, fehlen), **Veranstaltungsleitung** und **Technik** (die Dienste aus dem
  Personal aller Events – Auf-/Abschließen und House Rep. bzw. VfV, „🔗 Person ·
  Rolle (Event)“, Link auf Planung › Personal – dazu eigene Einträge),
  **Persönlich** (nur die eigenen Dienste: als Konto selbst oder über das
  verknüpfte Gewerk; sieht jeder mit Kalender-Recht – ein eigener
  Dienst steht nur einmal da, schräg gestreift in den Farben beider Ebenen),
  alle anderen
  Ebenen (Wartung, Towers …) eigene Einträge. Eigene Einträge
  (`CalendarEntry`, ganztägig oder mit Uhrzeit, Ende einschließlich) legt an,
  ändert und löscht, wer die Ebene bearbeiten darf (`CalendarEntryPolicy`); auf
  Events, Anfragen und Persönlich gibt es keine. Rechte je Ebene an Rolle und
  Konto (das Höchste gilt), Admins alles. Anders als in der PHP-Version: je Tag
  Events zuerst; Monat und 4 Wochen zeigen je Woche höchstens vier Bahnen, der
  Rest steht als „+n weitere“ da und führt zum Tag. Zeitraster 6–22 Uhr, länger,
  wenn ein Eintrag früher beginnt oder später endet. Am Handy eine Liste der
  Tage. Die Freitermin-Suche (`/anfragen/freitermin`) fehlt noch.
- **Persönlicher Kalender als Abo** (`/kalender/persoenlich.ics`, 13.10.2026,
  neu gegenüber der PHP-Version): die eigenen Dienste mit Uhrzeit (UTC), ohne
  Uhrzeit ganztägig, 90 Tage zurück bis 18 Monate voraus, fragliche Events
  vorläufig, stornierte abgesagt. Schlüssel je Konto, Adresse im Profil („Neue
  Adresse“ macht die alte ungültig); gespeichert verschlüsselt, gesucht über den
  SHA-256-Hash, im Audit maskiert. Angemeldet geht es auch ohne Schlüssel; nur
  aktive Konten, die „Persönlich“ lesen dürfen.
- **Profil:** Standard-Ansicht des Kalenders (Monat, Woche, Tag, 4 Wochen) und die
  Abo-Adresse; das aktuelle Passwort fragt das Profil nur beim Passwortwechsel.
- **Zugangsdaten** (Verwaltung › Benutzer, 18.10.2026): Abschnitt „Anmeldung“ mit
  E-Mail und Passwort. „Passwort generieren“ füllt 16 Zufallszeichen in
  Vierergruppen ohne verwechselbare Zeichen ein (`App\Support\AccessData`), das
  Feld lässt sich anzeigen und kopieren. „Zugangsdaten per E-Mail senden“ (beim
  Bearbeiten „Neues Passwort per E-Mail senden“, nur mit neuem Passwort und für
  aktive Konten) schickt nach dem Speichern Adresse der Anmeldung, E-Mail und
  Passwort (`App\Mail\AccessDataMail`, Absender die Venue, Antworten an ihre
  Antwortadresse, sonst an den Admin). Geht die Mail nicht raus, bleibt das
  Konto gespeichert, mit Hinweis. Im Änderungsprotokoll steht nur
  „Zugangsdaten verschickt an …“, nie das Passwort.
- **Passwortwechsel beim ersten Anmelden** (18.10.2026): Hat ein Admin das
  Passwort vergeben – beim Anlegen immer, beim Bearbeiten mit neuem Passwort für
  ein fremdes Konto –, steht am Konto `must_change_password`. Bis die Person ein
  eigenes festgelegt hat, führt jede angemeldete Seite (auch Druckansichten und
  Downloads) auf „Neues Passwort festlegen“ (`/passwort-aendern`,
  `RequirePasswordChange`, `ChangePassword`): neues Passwort zweimal, nicht das
  bisherige; danach weiter zur angefragten Seite. Abmelden geht immer. Ein
  eigenes Passwort im Profil oder über „Passwort vergessen?“ erfüllt die Pflicht
  ebenso. Die Benutzerliste zeigt „Muss Passwort ändern“, solange es offen ist.
  Bestehende Konten waren nicht betroffen.
- **„Venue“ statt „Halle“** (19.10.2026): Oberfläche, Mails, Hilfetexte, Doku und
  Kommentare sagen „Venue“. Die Seite Verwaltung › Venue liegt unter `/venue`,
  das Logo unter `/venue/logo`; `/halle` und `/halle/logo` leiten dauerhaft
  weiter. Der Platzhalter `{venue}` ersetzt `{halle}` in den Vorgaben für
  Daysheet- und Costing-Mails; `{halle}` wird weiter ersetzt, gespeicherte Texte
  stellt die Migration `2026_10_19_100000` um. `vc:demo-data` nimmt `--venue=`.
  Bezeichnungen in Daten bleiben, etwa der Kontakt „Hallenmeister“.
- **Personal: Benutzer, Kontakte, Gewerke** (14.10.2026, ersetzt die Mitarbeiter
  der Stammdaten und die Verknüpfung Konto ↔ Mitarbeiter vom 13.10.2026):
  - Personen-Konten (Kontotyp „Mitarbeiter der Venue“, vorher „… der Halle“, und
    „Freelancer“) tragen Positionen (VL, VfV …), Kürzel und Telefon selbst
    (Verwaltung › Benutzer) und werden direkt im Personal eingetragen.
  - **Kontakte** (Stammdaten › Kontakte, Recht „Kontakte“ – Schlüssel
    `admin_mitarbeiter` wie zuvor die Mitarbeiter): Personen oder Funktionen ohne
    Anmeldung, etwa der „Hallenmeister“ fürs Aufschließen; Name, Kürzel, Telefon,
    E-Mail, Positionen, Notiz, archivieren statt löschen, sobald benutzt. Wer
    Kontakte bearbeiten darf, legt einen neuen auch direkt im Personal-Feld an.
  - Gewerk-Accounts sind mit einem Gewerk verknüpft, beliebig viele je Gewerk;
    dessen Dienste und Events sind dann die des Kontos.
  - Übernahme (Migration `2026_10_14_100000`): Ein Mitarbeiter ging in das
    Personen-Konto auf, mit dem er verknüpft war oder das eindeutig dieselbe
    E-Mail-Adresse bzw. denselben Namen hatte (samt Diensten, Positionen, Kürzel,
    Telefon); alle übrigen wurden Kontakte mit ihren Diensten. ipa: Nico Bein und
    Tamara Beck in ihre Konten, sechs Kontakte.
  - Daysheet und „Meine Events“ zeigen bei den Ansprechpartnern (Projektleitung,
    Auf-/Abschließen, House Rep. früh/spät) Telefon und E-Mail.
- **Zählerstände** (`/zaehler`, Recht „Zählerstände“, 15.10.2026; in der
  PHP-Version je Zähler `/zaehler/strom` usw.): Welche Zähler es gibt, legt die
  Venue unter Stammdaten › Zähler fest (Recht „Stammdaten“): Name, Einheit,
  Zählernummer, Standort, „je Event ablesen“, aktiv; löschen nur ohne Stände.
  Zähler „je Event“ stehen im Event unter Durchführung › Betrieb (Stand Anfang
  am Morgen des ersten, Ende am Abend des letzten Event-Tags; ziehen mit, wenn
  sich die Tage ändern). Die Seite zeigt je Zähler und Jahr Kennzahlen
  (Verbrauch gegen dieselben Tage im Vorjahr, bei Events Anteil und Verbrauch je
  PAX, sonst Schnitt je Tag, letzter Stand), Verbrauch je Monat gegen das
  Vorjahr und je Event als Diagramm und Tabelle, darunter die Stände. Freie
  Ablesungen („Stand erfassen“, ändern, löschen) mit Bearbeiten-Recht; Stände
  eines Events nur im Event. Jeder Stand muss zwischen seine Nachbarn passen,
  außer bei „Zählerwechsel“ (neuer Zähler, seit dem vorigen Stand zählt nichts).
  Auswertung (`App\Support\MeterStats`): Verbrauch zwischen zwei Ständen
  gleichmäßig auf die Zeit verteilt; Monate ohne Stand davor und danach bleiben
  leer. Migration `2026_10_15_100000`: Zähler „Strom“ (kWh, je Event) mit den
  bisherigen Stromzählerständen (Demo: 15 Events, ipa: keine).
- **Kalender für Externe** (`/extern/kalender`, Recht „Kalender (extern)“):
  dieselben lesbaren Ebenen, nur lesen; Links nur zu Events, an denen das Konto
  beteiligt ist (dann „Meine Events“, fett mit ↗), alles andere ohne Link. Das
  Recht allein öffnet das Panel – ohne „Meine Events“.
- **Anfragen** (`/anfragen`, Recht „Anfragen“, 13.10.2026) wie in der
  PHP-Version: Titel, Veranstalter, Optionsfrist (Vorgabe 28 Tage, überschritten
  rot), Kommentar, ein oder mehrere Wunschtermine und Eckdaten fürs Event
  (Kategorien, Bereiche, Bestuhlung). Je Tag zählt der Eingang der offenen
  Anfragen: 1. Option, 2. Option … (`App\Support\Inquiries`, jedes Mal neu
  berechnet); belegt ein bestätigtes, nicht abgeschlossenes Event den Tag, heißt
  der Termin „Nicht verfügbar“. Bestätigen (nur offene, verlangt zusätzlich
  „Events bearbeiten“) macht aus einem freien Termin ein bestätigtes,
  ganztägiges Event mit den Eckdaten und führt hinein; Zurückziehen archiviert.
  Wie in der PHP-Version hat die 1. Option beim Bestätigen keinen Vorrang – eine
  2. Option lässt sich bestätigen und belegt dann den Tag (offen zur
  Entscheidung). Ändern nur, solange offen. Liste nach erstem Termin, Filter
  Status (Vorgabe offen), Zeitraum (Vorgabe zukünftige), Veranstalter. Anders als
  in der PHP-Version (dort Recht der Events) ein eigener Bereich „Anfragen“; das
  Kürzen des Titels um ein Veranstalter-Kürzel beim Bestätigen
  (PromoterPrefix) fehlt.
- **Kalender-Feed** `/kalender/events.ics` wie in der PHP-Version: alle
  bestätigten Events ganztägig, Zugriff mit geheimem Schlüssel (Einstellung der
  Venue) oder angemeldet mit Kalender-Recht. Adresse unter Events › „Kalender
  abonnieren“ und Verwaltung › Venue (neu erzeugen, ausschalten).
- **Dateien** wie in der PHP-Version: Planung › Dateien zum Hochladen (Tag,
  Anzeigename, max. 25 MB, Archive nur für Admins; die Dateiauswahl bietet nur
  erlaubte Endungen an), neue Version hochladen
  (Versionsnummer zählt hoch, „Stand: Datum · vN“), Löschen; auf der Übersicht
  nach Tag gruppiert. Dateien liegen nicht öffentlich (Disk „local“),
  heruntergeladen wird über `/dateien/{id}/download` mit Rechteprüfung.
  `vc:import` kopiert die Dateien aus der PHP-Version mit. Datei-Tags unter
  Stammdaten (archivieren statt löschen, sobald benutzt). Übergreifende
  Dateien werden angezeigt; die zentrale Bibliothek zum Verknüpfen
  (PHP: `/dateien`) folgt noch.
- **Bühnenplan** (`/events/{id}/buehnenplan`) wie in der PHP-Version:
  Draufsicht mit Podest-Raster (Standard 14×8, Hausbestand, angemietet),
  Wings, Treppen, Raummitte, Raumbegrenzung und Rückwand; daneben
  Plan-Einstellungen (Versatz Wings und Treppen, Abstand Rückwand) und Zahlen.
  Druckansicht A4 quer und SVG-Datei. Verlinkt im Kopf des Workspace, unter
  Planung › Bühne und auf der Übersicht. Für alle 239 Events zeichengleich mit
  der PHP-Version geprüft (vor der Übernahme von „sonst. Podeste“, siehe
  unten). Der Planrahmen 25 × 15 m ist fest: keine echte Raumgröße, er legt nur
  das Seitenverhältnis von Bühne zu Plan fest.
- **Bühnen-Stammdaten je Venue** (Verwaltung › Venue, `App\Support\StageSettings`,
  01.10.2026): Standardbühne (14 × 8 m), Zusatzpodeste im Hausbestand (24 × 2×1 m,
  2 × 1×1 m), Rollipodest in Standardgröße (4 × 3 m), Standardhöhe (1,4 m) und
  wählbare Höhen, Podest-Bestand (86) und Mietanteil (62), Beschriftung im Plan
  („IPA stage“) und Raumbegrenzung (±10 m). Voreingestellt sind die Werte der
  Inselpark Arena; damit sind alle 239 Pläne, Kurzformen und Abrechnungszahlen
  unverändert (geprüft). Bestand und Mietanteil gelten rückwirkend für alle
  Events – so entschieden am 01.10.2026.
- **Bühne:** Maße, Wings, Rollipodest, Höhe und die Podest-Rechnung wie in
  der PHP-Version; in der Event-Liste die Kurzform „14×8 H1,4 62P“ (gelb bei
  anderer als der Standardhöhe, rot bei mehr Podesten als im Bestand). Die Summe
  wird nur neu berechnet, wenn sich ein Maß ändert – die gespeicherten Summen
  bleiben sonst stehen (mit allen 239 Zeilen geprüft, bestätigt am 01.10.2026).
  AppSheet liefert keine Summe mit, die PHP-Version berechnet sie aus den Maßen.
  „sonst. Podeste“ aus AppSheet (12×) zählen seit 01.10.2026 als „Sonstige“
  mit, in beiden Versionen (PHP: Import und Migration 056): bei 11 Events
  zusammen 76 Podeste mehr zusätzlich berechnet, Culcha Candela liegt mit 89
  über dem Bestand. Die „Anmerkungen Bühne“ aus AppSheet (17×) stehen im Feld
  „Anmerkungen Bühne“ (bearbeitbar, kursiv im Bühnenplan). Der
  Sold-Out-Award (auch Bühnen-Tabelle) steht in der Durchführung,
  ja/nein/leer wie in der PHP-Version.
- **Detailansicht** (nur lesen): Kerndaten, Zeiten, Finanzen (mit Recht),
  Rollen mit Uhrzeit, Räume, Leistungen, Bühne, Checkliste, Sold-Out-Award.
- **Buchhaltung** mit Offen/Abgeschlossen/Alle/Endabrechnung/Archiv und
  Finanz-Warnung – mit echten Daten zahlengleich zur PHP-Version. Spalte
  „Zus. Podeste“: über den im Mietpreis enthaltenen hinaus (47 Events).

- **Änderungsprotokoll** (`/audit`, Verwaltung › Audit, Recht „Audit“ wie in der
  PHP-Version; 01.10.2026): jede Änderung über Eloquent automatisch
  (`App\Support\Audit`, Listener im AppServiceProvider) – angelegt, geändert,
  gelöscht, mit Werten vorher/nachher, Benutzer, Zeitpunkt, IP und Browser.
  Passwörter und Feed-Schlüssel als „***“, Unterschriften als „[Unterschrift]“.
  Ohne Eloquent geschriebene Stellen melden sich selbst (Raumbelegung,
  Eingangsrechnungen), Rollen-Zuordnungen über das Pivot-Modell `RoleUser`,
  `vc:import` mit einem Eintrag je Lauf. Anzeige mit deutschen Feldnamen und
  Namen statt IDs (`App\Support\AuditPresenter`), Filter nach Event, Bereich,
  Aktion, Benutzer, Zeitraum; im Event-Kopf „Verlauf“ (gefiltert aufs Event).
- **Codes Tageszugang** (`/codes`, Recht „Codes“) wie in der PHP-Version:
  aktuell gültiger Code mit ▲ (gehört zur Eingabe am Zugangssystem, wird nicht
  gespeichert), Code nach Datum (auch `?datum=JJJJ-MM-TT`), die nächsten 30 Tage.
  Gepflegt wird nicht hier: Die Codes kommen per Import (859 Codes bis Ende 2028).
  Für den Go-live-Import aus AppSheet: Tabelle „Codes“ (`Codes.code`,
  `Codes.validFrom`, ein Code je Tag).

- **Extern-Bereich „Meine Events“** (`/extern/events`, Recht „Events (extern)“,
  02.10.2026) für Freelancer und Gewerke mit eigenem Konto: nur Events, an
  denen das Konto beteiligt ist (`App\Support\Involvement` wie
  UserInvolvement der PHP-Version: Konto im Personal; Gewerke-Konto über das
  Gewerk im Personal oder in den
  Gewerken). Nur lesend: Zeiten, Ansprechpartner (Projektleitung,
  Auf-/Abschließen, House Rep. früh/spät, Ansprechpartner vor Ort, mit Telefon
  und E-Mail), Gewerke, Bühne mit Plan, Dateien,
  Notizen – nichts aus Buchhaltung, Buchung, Gästen, Betrieb
  (`App\Support\ExternSheet`). Dateien und Notizen sind sichtbar, bis jemand
  „Für Externe verbergen“ anhakt. Externe kommen ins selbe Panel und sehen nur
  „Meine Events“ und „Profil“; intern zeigt „Ansicht Extern“ im Event-Kopf, was
  Externe sehen. Den Kalender für Externe gibt es seit 13.10.2026 (siehe
  Kalender), Schichten extern fehlen noch.
- **Daysheet** (02.10.2026): „Daysheet“ im Event-Kopf bzw. Durchführung ›
  Daysheets, nur mit Schreibrecht auf Events. Vorbelegt: „An“ mit dem
  Standard-Empfänger der Venue, BCC mit den E-Mail-Adressen der beteiligten
  Gewerke, Betreff und Text aus Verwaltung › Venue (Platzhalter {event},
  {datum}, {venue}, {link}, {gueltig_bis}; bis 19.10.2026 hieß {venue} {halle},
  das gilt weiter) – alles vor dem Versand änderbar.
  Die Empfänger bekommen einen Link ohne Konto, gültig ab Versand bis 23:59 Uhr
  am Tag nach der Veranstaltung (mehrtägig: nach dem letzten Tag; Vorgabe vom
  02.10.2026), jederzeit sperrbar; die Seite lässt sich drucken oder als PDF
  speichern. Gespeichert wird nur der Hash des Schlüssels. Inhalt vorläufig wie
  der Extern-Bereich, wird noch festgelegt – an einer Stelle
  (`App\Support\ExternSheet`). Versand per SMTP über all-inkl von
  no-reply@vcontrol.eu mit dem Namen der Venue (`App\Support\MailIdentity`);
  Antworten gehen an die Antwortadresse unter Verwaltung › Venue › E-Mail, ohne
  sie an den Eventmanager, der verschickt hat. Gilt auch für Schadensmeldungen. Status: in der
  Event-Liste Spalte „DS“ mit grünem Haken, sobald ein Daysheet verschickt ist
  (gesperrte Links zählen nicht), im Dashboard (Karte Durchführung) „Daysheet
  versendet am …“ bzw. bei kommenden Events „noch nicht versendet“.

Noch nicht: Leistungsgruppen
bearbeiten, Datei-Bibliothek (übergreifende Dateien verknüpfen),
Freitermin-Suche, Sortierung
„Finanz-Warnung zuerst“.

- **Leistungen:** Die PHP-Version legt je Event alle 19 Leistungen an
  (4.465 Zeilen), in keiner einzigen ist ein Gewerk oder Anbieter eingetragen.
  Inhalt tragen 1.252 Zeilen (wer stellt die Leistung: Venue 341,
  Veranstalter 827; dazu 214 Notizen). Übernommen werden nur diese plus die
  Aktiv-Schalter (zusammen 1.259); eine fehlende Zeile heißt „nicht festgelegt“.
- **Rollen** mit Uhrzeit von/bis (im Bestand 5 von 367, z. B. VfV 03:00–17:30).
  Zur Wahl stehen je Rolle nur passende: VfV früh/spät nur Konten und Kontakte
  mit Position VfV und Gewerke mit diesem Leistungsbereich (12.10.2026), House
  Rep. früh/spät nur Konten und Kontakte mit Position VL (13.10.2026),
  Auf-/Abschließen jede Person (Personen-Konto oder Kontakt), die
  Projektleitung jedes Konto, jeder Kontakt und jedes Gewerk; schon
  Eingetragenes bleibt wählbar.
  Leert man die Person, verschwindet die Rolle samt Uhrzeit.
- **Rollen:** Die „Projektleitung“ steckt in den Rollen (`pl`, 123 Events),
  nicht im leeren Feld `pl` am Event. Rollen verweisen per Morph-Map auf
  Benutzer, Kontakt oder Gewerk (`user`/`contact`/`trade`; die PHP-Version
  hatte `employee` statt `contact`).
- **Räume:** Ein Raum, den ein Event belegt, lässt sich nicht mehr löschen
  (Policy und Fremdschlüssel). Die PHP-Version löschte ihn samt Belegungen.
- **Saalplan (neu, 12.10.2026):** Standard-Saalpläne pflegt, wer die Stammdaten
  der Venue bearbeitet (Stammdaten › Saalpläne, `SeatingPlanPolicy`): PDF, Name,
  genutzte Bereiche, Bestuhlung. Je Event unter Buchung › Daten („Venue &
  Publikum“) entweder einer davon – Bereiche und Bestuhlung werden übernommen
  und lassen sich anpassen – oder ein eigener PDF-Plan (`events.seating_plan_id` bzw.
  `seating_plan_path`; das eine löst das andere ab, `Event::booted`). Wer Events
  nur lesen, aber die Buchhaltung bearbeiten darf, ändert ihn in der
  Leseansicht über „Saalplan ändern“. Externe Ansicht und Daysheet zeigen den
  Saalplan mit den genutzten Bereichen und der Bestuhlung; ausgeliefert wird über
  `SeatingPlanController` (Rechte wie bei den Event-Dateien, Daysheet über den
  Schlüssel), angezeigt nur, was am Inhalt als PDF erkannt ist.
- **Module (13.10.2026):** Bühnenplan, Saalplan, Gästeliste und Buchhaltung gibt
  der System-Admin je Venue frei, je nachdem, was sie gebucht hat (Verwaltung ›
  System, `App\Access\Module`). Ohne Freigabe fehlen sie für alle. Bühnenplan:
  der Reiter Planung › Bühne mit Maßen und Podest-Rechnung, die Bühnenplan-Seite
  mit Druck und SVG, die Bühnenwerte unter Verwaltung › Venue, die Spalten Bühne
  (Event-Liste) und Zus. Podeste (Buchhaltung) und die Bühne für Externe – der
  Sold-Out-Award unter Betrieb bleibt. Saalplan: Standard-Saalpläne, Auswahl oder
  Upload am Event und der Abschnitt Saalplan für Externe (Bereiche und
  Bestuhlung am Event bleiben). Gästeliste: Reiter und Druckansicht.
  Buchhaltung: Liste, Reiter, Finanz-Warnung und ihre Feldoptionen – die
  Fortschrittsprüfungen Vertrag und Preisliste entfallen dann. Gespeichertes
  bleibt und ist mit der Freigabe wieder da.
- **Preislisten & Costing (13.10.2026, Modul):** Preislisten der Venue unter
  Stammdaten › Preislisten (`price_lists`, `price_list_items`): Kostenpunkte nach
  Abschnitten mit Hinweis, Einheit, Preis (leer: nach Aufwand; Einheit „%“:
  Zuschlag, im Costing ist der Faktor dann der Betrag in €), Mindestbetrag,
  Vorgabe-Faktor, „Faktor = PAX erwartet“ und „Im Costing“ (aus: startet im
  neuen Costing ausgeschaltet, etwa Alternativen wie die Grundmiete bestuhlt). Pflegen mit Stammdaten-Recht, lesen
  auch Buchhaltung und Eventmanager. Das Event wählt seine Preisliste unter
  Buchung › Daten (`events.price_list_id`); bis 13.10.2026 war sie eine Feldoption
  der Buchhaltung (`event_finances.price_list`) – die Migration hat jeden Wert zu
  einer Preisliste ohne Kostenpunkte gemacht. Das Costing (`costings`,
  `costing_items`, Buchung › Costing und Menüpunkt Costings) übernimmt
  Kostenpunkte, Preise und Vorgabe-Faktoren, der Eventmanager passt Faktoren an
  und ergänzt Posten; Preis und Einheit der Liste bleiben fest (auch beim
  Speichern, `fixed_price`). Jeder Posten hat einen Schalter „Aktiv“: Faktor 0
  steht mit 0,00 € im PDF, nur ausgeschaltete Posten fehlen dort und in der
  Summe. Den Abschnitt zeigt der Editor nicht, das PDF gliedert nach den
  Abschnitten der Preisliste, ergänzte Posten unter „Weitere Posten“.
  Verschickt wird per Mail mit PDF (Dompdf), danach bleibt die Fassung,
  Änderungen gehen als neue Fassung. Alle Preise netto, 19 % MwSt. kommen im PDF
  dazu, gerechnet in Cent (`Costing::percentOf`). Beträge stehen in Formularen mit
  Tausenderpunkt (`MoneyInput`, `App\Support\Money`). Einheiten sind die
  Feldoption „Einheit (Preislisten)“.
- **Costing-Einstellungen (Verwaltung › Venue, `CostingSettings`):** welche Rollen
  den Menüpunkt Costings sehen (die Liste und darin jedes Costing) und welche das
  Costing am Event; Admins immer. Bis zum ersten Speichern gilt: wer Events
  bearbeitet oder die Buchhaltung sieht. Erstellen und verschicken darf davon,
  wer Events bearbeitet. Zwei Schalter, ob die Ansicht für Externe und das
  Daysheet das zuletzt verschickte Costing als PDF zeigen (Vorgabe: nein;
  `events.costing`, `daysheet.costing`). Dazu Betreff, Text und feste BCC-Adressen
  der Costing-Mail. Das Logo der Venue (Verwaltung › Venue, PNG oder JPG,
  `App\Support\VenueLogo`) steht auf dem Costing mittig in der Kopfzeile jeder
  Seite statt ihres Namens, rechts im Kopf der Daysheet-Seite (auf dem Handy über
  dem Titel) und im Kopf der Mails (Daysheet, Costing, Passwort vergessen;
  eingebettet, `vendor/mail/html/venue`). Ohne Logo bleibt es beim Namen; im Fuß
  von Mails und Daysheet-Seite steht weiter „erstellt mit VenueControl“ mit
  dessen Logo, im Fuß des Costing-PDFs nur die Zeile.

## Offene Punkte

1. **„Durchführung abgeschlossen“ und „Event abgeschlossen“** waren in allen
   239 Events gleich gesetzt (128× beide nein, 111× beide ja). Entschieden am
   30.09.2026: ein Schalter, „Event abgeschlossen“ (`closed`); `doing_closed`
   entfällt. Die PHP-Version schreibt ihn ohnehin nicht mehr.
2. **Personal doppelt:** `vc_event_staff` hält VL und Promoter-Vertretung als
   freien Text, `vc_event_role_assignments` dieselben Rollen strukturiert.
   Empfehlung: nur die strukturierte Zuordnung übernehmen; der freie Text
   wird beim Import zur Notiz, falls er abweicht.
3. **Ticketing** ist im Bestand immer ein einzelner Wert. Einfach- oder
   Mehrfachauswahl? Empfehlung: einfach.
4. **Workspace:** Event-Seite mit drei Tabs (Buchung, Planung, Durchführung)
   wie in der PHP-Version, darin die Abschnitte; Listen (Gäste, Schäden …) als
   eingebettete Tabellen. Bühnenplan als eigene Seite mit Alpine statt
   Livewire.
