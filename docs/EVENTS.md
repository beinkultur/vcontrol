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
| `event_operations` | `vc_event_operations` | Strom (Zählerstände, Verbrauch), Backstages/Büros, Buspower, Haus-Delay |
| `event_pr` | `vc_event_pr` | PR-Datum, PR-Status |
| `event_checklists` | `vc_event_checklist` | 15 Punkte ja/nein/entfällt |
| `event_room` | `vc_event_rooms` | Raumbelegung mit Art (Backstage/Büro) |
| `event_services` | `vc_event_trades` | Leistungen je Event (4.465 Zeilen): Leistungscode, Gewerk oder freier Anbieter, verantwortlich Halle/Veranstalter, Notiz |
| `event_service_groups` | `vc_event_trade_active`, `vc_event_trade_groups` | welche Leistungsgruppen aktiv sind |
| `event_assignments` | `vc_event_role_assignments` | Rollen am Event (VL, VfV …) an Mitarbeiter, Gewerk oder Benutzer, mit Zeiten |
| `event_incoming_invoices` | `vc_event_incoming_invoices` | die sechs erwarteten Eingangsrechnungen |

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
  Platzhalter, obwohl die Daten existieren – hier ist sie bearbeitbar.
- Gästeliste mit Druckansicht für den Einlass (A4, nach Name sortiert, Summe,
  Spalte zum Abhaken).
- **Durchführung › Betrieb** (01.10.2026): Räume einmal je Raum mit Backstage /
  neutral / Büro; Bus-Strom als Anzahl der Anschlüsse (0–5, einzeln
  abgerechnet, Spalte in der Buchhaltung); Stromzähler Stand Anfang und Ende,
  der Verbrauch wird daraus berechnet (kein Eingabefeld mehr); „Check“ wie in
  der PHP-Version: Sonderreinigung, Haus-Delay, Miete Elektro-Ameise, Haus-Rig
  ab 7 Uhr, Sold-Out-Award, dazu PAX abgerechnet. Die Zählerstände gibt es nur
  hier, nicht in der PHP-Version.
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
  bis 20 Fotos (je 15 MB), „behoben“ setzen Event-Operationen oder die
  Buchhaltung; nicht löschbar. Übersicht unter Protokolle › Schäden, dort auch
  allgemeine Schäden ohne Event. Neue Schäden gehen per Mail an
  `VC_DAMAGE_NOTIFY` (Komma-Liste), sonst an alle aktiven Benutzer mit der Rolle
  „hausmeister“. Fotos liegen nicht öffentlich, Auslieferung über
  `/schaeden/{id}/foto/{n}` mit Rechteprüfung; `vc:import` übernimmt die
  Schäden samt Fotos (50 Schäden, 1 Foto, bytegleich geprüft).
- **Event-Operationen in der Ansicht:** Übergabe, Bestellscheine, Checklisten
  und Schäden sind auch in der Lese-Ansicht des Events bearbeitbar, wenn die
  Rolle „Event-Operationen“ bearbeiten darf (etwa der Hausmeister: Events
  lesen, Operationen bearbeiten) – wie in der PHP-Version. Wer die
  Bearbeiten-Seite eines Events ohne Schreibrecht aufruft (z. B. über den Link
  in der Schadensmail), landet in der Ansicht, Phase und Bereich bleiben.
- **Kalender-Feed** `/kalender/events.ics` wie in der PHP-Version: alle
  bestätigten Events ganztägig, Zugriff mit geheimem Schlüssel (Einstellung der
  Halle) oder angemeldet mit Kalender-Recht. Adresse unter Events › „Kalender
  abonnieren“ und Verwaltung › Halle (neu erzeugen, ausschalten).
- **Dateien** wie in der PHP-Version: Planung › Dateien zum Hochladen (Tag,
  Anzeigename, max. 15 MB, Archive nur für Admins), neue Version hochladen
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
- **Bühnen-Stammdaten je Halle** (Verwaltung › Halle, `App\Support\StageSettings`,
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

- **Codes Tageszugang** (`/codes`, Recht „Codes“) wie in der PHP-Version:
  aktuell gültiger Code mit ▲ (gehört zur Eingabe am Zugangssystem, wird nicht
  gespeichert), Code nach Datum (auch `?datum=JJJJ-MM-TT`), die nächsten 30 Tage.
  Gepflegt wird nicht hier: Die Codes kommen per Import (859 Codes bis Ende 2028).
  Für den Go-live-Import aus AppSheet: Tabelle „Codes“ (`Codes.code`,
  `Codes.validFrom`, ein Code je Tag).

Noch nicht: Leistungsgruppen
bearbeiten, Datei-Bibliothek (übergreifende Dateien verknüpfen),
Kalender, Anfragen und
Freitermin, Extern-Portal, Audit-Log, Sortierung
„Finanz-Warnung zuerst“.

- **Leistungen:** Die PHP-Version legt je Event alle 19 Leistungen an
  (4.465 Zeilen), in keiner einzigen ist ein Gewerk oder Anbieter eingetragen.
  Inhalt tragen 1.252 Zeilen (wer stellt die Leistung: Halle 341,
  Veranstalter 827; dazu 214 Notizen). Übernommen werden nur diese plus die
  Aktiv-Schalter (zusammen 1.259); eine fehlende Zeile heißt „nicht festgelegt“.
- **Rollen** mit Uhrzeit von/bis (im Bestand 5 von 367, z. B. VfV 03:00–17:30).
  Leert man die Person, verschwindet die Rolle samt Uhrzeit.
- **Rollen:** Die „Projektleitung“ steckt in den Rollen (`pl`, 123 Events),
  nicht im leeren Feld `pl` am Event. Rollen verweisen per Morph-Map auf
  Mitarbeiter, Gewerk oder Benutzer (`employee`/`trade`/`user`, dieselben
  Werte wie in der PHP-Version).
- **Räume:** Ein Raum, den ein Event belegt, lässt sich nicht mehr löschen
  (Policy und Fremdschlüssel). Die PHP-Version löschte ihn samt Belegungen.

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
