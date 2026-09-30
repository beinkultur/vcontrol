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

Später, jeweils mit ihrem Modul: Gäste, Notizen, Dateien, Schäden,
Bestellscheine, Übergabeprotokolle, Ablaufplan (`vc_event_attachments`).

## Stand der Übernahme (30.09.2026)

Übernommen: Kern, alle sechs 1:1-Tabellen, Leistungen, Leistungsgruppen,
Rollen, Raumbelegung, Eingangsrechnungen, Gästeliste, Notizen.

Bedienbar:
- **Event-Liste** wie in der PHP-Version (offen/ab heute voreingestellt).
- **Workspace** Buchung (Stammdaten, Finanzen, Eingangsrechnungen, PR),
  Planung (Halle, Bühne, Zeiten, Räume, Rollen, Leistungen, Checkliste),
  Durchführung (Betrieb, Abschluss). VA-NR/VA-ID beim Anlegen. Notizen
  (Betreff, Text, Verfasser und letzter Bearbeiter) und Gästeliste als
  eingebettete Tabellen. Die Checkliste zeigt die PHP-Version nur als
  Platzhalter, obwohl die Daten existieren – hier ist sie bearbeitbar.
- **Bühne:** Maße, Wings, Rollipodest, Höhe und die Podest-Rechnung wie in
  der PHP-Version; in der Event-Liste die Kurzform „14×8 H1,4 62P“ (rot bei
  anderer Höhe als 1,4 m oder mehr Podeste als im Bestand). Bestand (86) und
  Mietanteil (62) stellt jede Halle unter „Halle“ ein. Die Summe wird nur neu
  berechnet, wenn sich ein Maß ändert – die Summen aus AppSheet bleiben sonst
  stehen (mit allen 239 Zeilen geprüft). Die AppSheet-Felder „Anmerkungen“
  (17×) und „sonst. Podeste“ (12×), die die PHP-Version nicht mehr zeigt,
  erscheinen schreibgeschützt. Der Sold-Out-Award (auch Bühnen-Tabelle) steht
  in der Durchführung, ja/nein/leer wie in der PHP-Version.
- **Buchhaltung** mit Offen/Abgeschlossen/Alle/Endabrechnung/Archiv und
  Finanz-Warnung – mit echten Daten zahlengleich zur PHP-Version.

Noch nicht: Bühnenplan (Zeichnung, Treppen, Rückwand), Fortschrittsanzeige
der Phasen, Leistungsgruppen bearbeiten, Rollen-Uhrzeiten, Dateien,
Protokolle (Übergabe, Bestellscheine, Schäden), Kalender, Anfragen und
Freitermin, Extern-Portal, Zugangscodes, ICS-Feed, Audit-Log, Druckansicht
der Gästeliste, Sortierung „Finanz-Warnung zuerst“.

- **Leistungen:** Die PHP-Version legt je Event alle 19 Leistungen an
  (4.465 Zeilen), in keiner einzigen ist ein Gewerk oder Anbieter eingetragen.
  Inhalt tragen 1.252 Zeilen (wer stellt die Leistung: Halle 341,
  Veranstalter 827; dazu 214 Notizen). Übernommen werden nur diese plus die
  Aktiv-Schalter (zusammen 1.259); eine fehlende Zeile heißt „nicht festgelegt“.
- **Rollen:** Die „Projektleitung“ steckt in den Rollen (`pl`, 123 Events),
  nicht im leeren Feld `pl` am Event. Rollen verweisen per Morph-Map auf
  Mitarbeiter, Gewerk oder Benutzer (`employee`/`trade`/`user`, dieselben
  Werte wie in der PHP-Version).
- **Räume:** Ein Raum, den ein Event belegt, lässt sich nicht mehr löschen
  (Policy und Fremdschlüssel). Die PHP-Version löschte ihn samt Belegungen.

## Offene Punkte

1. **„Durchführung abgeschlossen“ und „Event abgeschlossen“** sind in allen
   239 Events gleich gesetzt (128× beide nein, 111× beide ja). Zwei Schalter
   behalten oder zu einem zusammenfassen? Empfehlung: behalten, bis klar ist,
   ob sie fachlich wirklich dasselbe bedeuten.
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
