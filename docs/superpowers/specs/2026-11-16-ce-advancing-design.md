# Advancing-Fragenkatalog und Instanz ce.vcontrol.eu – Design

Stand 16.11.2026. Erste Lieferung für Campus Events (CE). Ziel: Der erste
Fragenkatalog (Event am 05.12.2026 in der LKH Arena) geht bis **Freitag,
20.11.2026** über VenueControl an den Veranstalter.

## Warum

Campus Events betreibt mehrere Venues (u. a. LKH Arena, Ritterakademie) und
nutzt VenueControl zunächst vor allem für das **Advancing**: Zu einem geplanten
Event bekommt der Veranstalter einen Fragenkatalog, in dem der
Veranstaltungsplaner schon eingetragen hat, was er weiß. Bisher läuft das über
ein Google-Formular mit vorausgefülltem Link („Advancing Fragenkatalog LKH Arena
Kultur“). Nachteile: Antworten liegen bei Google, nicht am Event; Vorbelegen
heißt, einen langen Link von Hand zu bauen; nach dem Absenden sieht der Planer
nicht, was der Veranstalter an seinen Vorschlägen geändert hat.

## Ziele dieser Lieferung

1. Eigene Installation **ce.vcontrol.eu** (gleicher Code wie ipa/demo), zunächst
   mit **einer** Venue „LKH Arena“.
2. **Advancing** als neues Modul: Katalog als Daten, je Event vorbelegen, Link
   ohne Konto per Mail, Formularseite für den Veranstalter, Rücklauf mit
   Markierung der Änderungen, Mail bei Abgabe.
3. Der Kultur-Katalog der LKH Arena, übernommen aus dem Google-Formular.

## Nicht in dieser Lieferung (später, eigene Designs)

- Mehrere Venues in einer Instanz (Lieferung 3/5 des Gesamtplans). Das Datenmodell
  hier muss das später zulassen (Katalog bekommt dann eine Venue), baut es aber
  nicht.
- Oberfläche zum Pflegen der Kataloge (Editor). Bis dahin per Import-Befehl.
- „Ins Event übernehmen“ (Antworten zurück in Zeiten, Ansprechpartner, Bühne).
- Abschaltbare Module PR, Gewerke, Bestellscheine, Übergabeprotokolle
  (Lieferung 2). Bis dahin sieht CE diese Reiter leer.
- Pflegbare Rollen am Event (die LKH-Funktionen statt der IPA-Rollen).
- Weitere Kataloge (Sport, Tagung …) und die Ritterakademie.

## Anti-Ziele

- Keine personenbezogenen Daten im Git: Namen, Mailadressen und Telefonnummern
  aus dem Google-Formular (Beispiel-Link) gehören nicht in Code, Katalog-Datei,
  Tests oder Doku. Personen kommen aus Benutzern/Kontakten der Datenbank.
- Der Link darf nichts preisgeben, was nicht im Katalog steht (kein
  Livewire-Zustand mit Event-Spalten, siehe Leck in „Meine Events“ bei
  IPA-Control im November 2026). Deshalb klassisches Formular, kein Livewire.
- Für ipa.vcontrol.eu ändert sich nichts: Das Modul ist dort aus (ipa hat
  `modules` gespeichert, ein neues Modul ist damit automatisch nicht frei).

## Entscheidungen des Nutzers (16.11.2026)

| Frage | Entscheidung |
|---|---|
| Termin | Erste Fassung so, dass der Link bis 20.11. raus kann; Venues und Module danach |
| Wer füllt aus | Veranstalter über **Link ohne Konto** (wie Daysheet), gilt bis Ende des Tages nach dem Event, sperrbar |
| Ändern | Veranstaltung, Datum und Ansprechpersonen der Venue nur ansehen; alles andere darf der Veranstalter ändern, auch Vorbelegtes |
| Runden | Zwischenspeichern und Absenden; nach dem Absenden weiter korrigieren und erneut absenden, bis der Planer abschließt oder der Link abläuft |
| Rücklauf | Mail bei jeder Abgabe; im Event Antworten mit Markierung, was gegenüber der Vorbelegung geändert ist; **nichts automatisch ins Event** |
| Kataloge | Mehrere je Venue möglich (Auswahl beim Start); für jetzt nur „Kultur“ |
| Ansprechpersonen der Venue | **Personenauswahl** aus Benutzern und Kontakten von VenueControl, Anzeige „Name \| E-Mail \| Telefon“, dazu „tba.“ |
| Umfang CE zum Start | An: Events, Kalender, Veranstalter, Kontakte, Dateien/Notizen, Daysheet, Advancing, Saalplan, Benutzer, Stammdaten, Audit. Aus (zuschaltbar): Buchhaltung, Preislisten & Costing, Anfragen, Zählerstände, Codes, Schichten/Schichtplanung, Protokolle, Event-Operationen, Inventar, Bühnenplan, Gästeliste |
| Mail | Absender `no-reply@vcontrol.eu` (vorhandenes Postfach) mit Namen der Venue, Antworten an die Antwortadresse der Venue; kein neues Postfach |
| Erscheinungsbild | Farbschema „VC Basic“ mit dem Logo der LKH Arena (Verwaltung › Venue); kein eigenes Schema |

## Ansätze (betrachtet)

1. **Katalog als Daten (gewählt).** Der Katalog ist eine Struktur aus
   Abschnitten, Infotexten und Fragen (JSON), die Antworten ebenso. Neue Fragen
   oder ein zweiter Katalog brauchen keinen Code; der spätere Editor ist ein
   Filament-Builder auf genau dieser Struktur.
2. Fest programmierter LKH-Katalog (Spalten je Frage): schneller, aber jede
   Änderung und jede weitere Venue braucht Code und Einspielen. Verworfen, weil
   CE mehrere Venues hat.
3. Google Forms behalten, VenueControl baut nur den vorausgefüllten Link:
   Antworten blieben bei Google, kein Rücklauf. Verworfen.

## Datenmodell

### Katalog (`advancing_templates`)

- `name` (z. B. „Kultur“), `intro` (Einleitungstext oben auf der Seite),
  `structure` (JSON, siehe unten), `is_active`, `sort_order`, Zeitstempel.
- Später kommt `venue_id` dazu (Migration ordnet bestehende Kataloge der ersten
  Venue zu). Jetzt nicht.

`structure` ist eine Liste von Abschnitten:

```json
[
  {"title": "Fahrzeuge", "text": "Infotext unter der Überschrift (optional)",
   "items": [
     {"key": "busse", "type": "number", "label": "Busse (Anz.)"},
     {"type": "info", "label": "Besucherparken", "text": "…"}
   ]}
]
```

Je Frage: `key` (eindeutig im Katalog, stabil – Antworten hängen daran),
`type`, `label`, optional `help`, `required`, `locked` (Veranstalter sieht nur),
`options` (bei `choice`), `binding` (Vorbelegung aus dem Event, s. u.).
Typen: `text`, `textarea`, `number`, `date`, `choice`, `yes_no`, `person`,
dazu `info` (nur Text, keine Antwort). Zeiten sind `text` – im Formular stehen
Vorschläge wie „16:00 Uhr ok?“, keine festen Uhrzeiten.

### Advancing je Event (`event_advancings`)

Eins je Event (Unique auf `event_id`).

- `event_id`, `advancing_template_id` (nullable, nur Herkunft), `template_name`,
  `intro`, `structure` (**Kopie** des Katalogs beim Start – spätere
  Katalog-Änderungen verändern laufende Advancings nicht).
- `prefill` (JSON key → Wert): was der Planer vorbelegt.
- `answers` (JSON key → Wert): was der Veranstalter **abweichend** von der
  Vorbelegung eingetragen hat. Regel beim Speichern: Ist der Wert gleich der
  aktuellen Vorbelegung, wird kein Eintrag gespeichert (bzw. entfernt). So
  wirkt eine spätere Änderung der Vorbelegung bei Fragen, die der Veranstalter
  nicht angefasst hat. Angezeigt wird `answers[key] ?? prefill[key]`.
- Link: `token_hash` (SHA-256, zum Finden), `token_encrypted` (verschlüsselt,
  damit der Planer den Link später kopieren und ansehen kann – wie der
  Kalender-Schlüssel im Profil), `expires_at`, `revoked_at`.
- Versand: `recipients_to`, `recipients_bcc` (JSON), `sent_at`, `sent_by`
  (Benutzer-ID, Ziel der Abgabe-Mail).
- Verlauf: `first_opened_at`, `last_saved_at`, `submitted_at` (letzte Abgabe),
  `submit_count`, `closed_at`, `closed_by_name`.
- `StampsAuthor` (created_by/updated_by mit Namen).
- Audit: Tabelle in `AuditPresenter::SUBJECTS`/`SUBJECT_AREAS` (Bereich Events).
  Speichert der Veranstalter, gibt es keinen angemeldeten Benutzer – der
  Audit-Eintrag nennt dann „Veranstalter (Advancing-Link)“.

Status ergibt sich aus den Feldern: Entwurf (nie versendet) → versendet →
geöffnet → abgegeben (n×) → abgeschlossen; gesperrt/abgelaufen zusätzlich.

### Personen-Fragen

Wert ist ein Verweis `user:ID`, `contact:ID` oder `tba`. Zur Wahl stehen aktive
Personen-Konten (Kontotyp Mitarbeiter/Freelancer) und nicht archivierte
Kontakte. Angezeigt (Planer und Veranstalter) wird immer der **aktuelle** Stand:
„Name | E-Mail | Telefon“ (leere Teile fallen weg). Ist die Person gelöscht,
steht „tba.“. Personen-Fragen sind im LKH-Katalog `locked`.

### Vorbelegung aus dem Event (`binding`)

Beim Start (und per Knopf „Aus dem Event neu vorbelegen“, nur leere Felder)
füllt VenueControl gebundene Fragen:

| binding | Quelle | Format |
|---|---|---|
| `event.title` | Titel | Text |
| `event.date` | erster Tag | Datum |
| `schedule.get_in`, `.load_in`, `.admission`, `.start_time`, `.end_time`, `.curfew`, `.load_out` | Planung › Zeiten | „11:30 Uhr“ |
| `onsite.name`, `onsite.email`, `onsite.phone` | Ansprechpartner vor Ort | Text |

Zuordnung im LKH-Katalog: Veranstaltung/Datum; Tourleitung/Örtlicher Vertreter
mit E-Mail und Mobil ← Ansprechpartner vor Ort; Get-In ← get_in; Einlass ←
admission; Showbeginn ← start_time; VA-Ende ← end_time; Curfew ← curfew;
Abbau Ende ← load_out (Annahme: „Load-out“ entspricht „Abbau Ende“).

## Abläufe

### Planer (Event › Planung › Advancing)

Neuer Reiter „Advancing“ unter Planung (nach „Kontakte“), nur mit dem Modul
Advancing. Sehen: wer das Event sieht. Ändern, versenden, abschließen: wer
Events bearbeiten darf.

- **Noch keins:** Katalog wählen (aktive Kataloge) → „Advancing starten“ legt es
  an und belegt aus dem Event vor.
- **Vorbelegen:** Formular aus der Struktur (Abschnitte, Infotexte grau, Fragen
  mit ihrem Typ). Speichern wie üblich. Neben jeder Frage, die der Veranstalter
  geändert hat: sein Wert, deutlich markiert („Veranstalter: …“), und darunter
  die Vorbelegung.
- **Status oben:** versendet am/an, gültig bis, zuletzt geöffnet/gespeichert,
  abgegeben am (n-mal), abgeschlossen.
- **Aktionen:** „Per Mail senden“ (An, BCC, Betreff, Text mit Platzhaltern
  `{event}`, `{datum}`, `{venue}`, `{link}`, `{gueltig_bis}`; An vorbelegt mit
  der E-Mail des Ansprechpartners vor Ort). Erneutes Senden schickt denselben
  Link. „Link kopieren“, „Als Veranstalter ansehen“ (öffnet die Seite),
  „Neuer Link“ (alter ungültig), „Sperren“/„Entsperren“, „Abschließen“/„Wieder
  öffnen“. Ohne Datum am Event oder nach Ablauf kein Versand (wie Daysheet).
- Mail-Vorgaben (Betreff/Text) mit Standardtext im Code; Einstellungen unter
  Verwaltung › Venue können später kommen.

### Veranstalter (`/advancing/{token}`)

- Ohne Konto, gedrosselt je IP, Header wie Daysheet (`noindex`, `no-referrer`,
  `no-store`). Ungültig/gesperrt/abgelaufen → Seite mit Grund (410).
- Kopf mit Logo und Name der Venue, Titel des Katalogs, Event und Datum,
  Einleitung. Danach die Abschnitte als normales HTML-Formular (POST mit CSRF,
  kein Livewire), handytauglich.
- `locked`-Fragen und Infotexte nur als Text. Alle anderen als Eingabefelder,
  gefüllt mit `answers ?? prefill`.
- Knöpfe „Zwischenspeichern“ und „Absenden“. Pflichtfelder prüft nur
  „Absenden“. Nach dem Absenden: Bestätigung oben, Seite bleibt bearbeitbar
  („Änderungen erneut absenden“). Abgeschlossen: alles nur lesen, Hinweis.
- Beim ersten Aufruf `first_opened_at` setzen.
- Mail bei jedem Absenden an `sent_by` (falls aktiv) und die Antwortadresse der
  Venue: Event, Datum, Zahl der geänderten Felder, Link in den Reiter. Scheitert
  der Versand, bleibt die Abgabe gespeichert (Log).

## Katalog „LKH Arena Kultur“

Quelle ist die Struktur des öffentlichen Formulars **ohne** Vorbelegung
(`FB_PUBLIC_LOAD_DATA_` der Seite `…/viewform`), ein Entwurf der Datei liegt
schon vor. Das Formular nutzt nur Kurzantwort, Absatz, Datum, Dropdown und
Titel/Text – keine Kästchen, Raster oder Datei-Uploads; die Typen oben reichen.
„Tourleitung / Örtlicher Vertreter“ ist **eine** Frage (eine Person mit E-Mail
und Mobil), vorbelegt aus dem Ansprechpartner vor Ort. Die Anzahl-Fragen
(Fahrzeuge) werden `number`, alle anderen Kurzantworten `text` (Antworten wie
„2x Tisch“ oder „Ja, 13:30 Uhr“ sollen möglich bleiben).

Aus dem Google-Formular übernommen: 46 Fragen, 4 Infotexte, 12 Abschnitte
(Ansprechpersonen Veranstalter/Produktion; Ansprechpersonen LKH Arena;
Fahrzeuge; Schedule; Bühne; Weitere technische Details; Merch; Einlass;
Hospitality; Catering; Sicherheit; Sonstiges). Texte und Preise wörtlich,
**ohne** die Personen aus den Auswahllisten (die sind `person`-Fragen).
Abgelegt als `database/advancing/lkh-arena-kultur.json` (keine
personenbezogenen Daten), eingespielt mit
`php artisan vc:advancing-catalog import <datei>` (legt an oder ersetzt den
gleichnamigen Katalog; laufende Advancings behalten ihre Kopie). Dazu
`… export <name>` zum Sichern.

## Modul und Rechte

- `Module::Advancing` („Advancing“), Beschreibung unter Verwaltung › System.
  Ohne Freigabe: kein Reiter, Route 404, kein Eintrag im Dashboard/Fortschritt.
- Kein eigener Bereich; die Rechte folgen „Events“ (lesen/bearbeiten).
- `EventAdvancingPolicy` als Unterklasse von `AreaPolicy`.
- `vc:check-access` (neue Seiten), `vc:smoke` (Reiter mit echten Daten).

## Instanz ce.vcontrol.eu

Vom Nutzer erledigt: Subdomain → `/ce.vcontrol.eu/public/`, Datenbank d0490195.
Noch vom Nutzer: SSL + http→https, Passwörter in die `.env`, erstes Konto mit
`php artisan vc:system-admin`, später Nextcloud-Datei-Ablage und KAS-Cronjob
für die Sicherung.

Ablauf (erst nach „deploy“): Code erstmals per rsync (deploy.sh setzt eine
vorhandene Installation voraus), Wurzel-`.htaccess` wie bei demo, `composer
install` (umask 022), `.env` aus `.env.example` (APP_URL, DB-Name/-Benutzer,
APP_KEY, Mail-Absender; Passwörter leer für den Nutzer, Rechte 600),
`migrate --force`, `filament:assets`, Katalog importieren. Danach Einstellungen:
Venue „LKH Arena“, Antwortadresse, Module (Saalplan, Advancing), abgeschaltete
Bereiche wie oben, Farbschema. Prüfen: Login, Asset-URL, Tests, `vc:check-access`,
`vc:smoke`. Später: Eintrag im Cockpit (eigenes Repo).

## Prüfen

- Tests (PHPUnit, SQLite im Speicher): Katalog-Import (gültig/ungültig),
  Vorbelegung aus dem Event, Regel „nur Abweichungen speichern“, Link
  gültig/gesperrt/abgelaufen/neu, Formularseite zeigt keine fremden Daten,
  `locked` lässt sich per POST nicht ändern, Pflichtfelder nur beim Absenden,
  Abgabe-Mail, Modul aus → 404 und kein Reiter, Rechte (lesen vs. bearbeiten).
- Abgeschottete Testkopie auf dem Server (700, danach löschen), PHP 8.3 und 8.5.
- Formularseite in Handy- und Desktopbreite ansehen.

## Rangfolge bis 20.11. (Entscheidung des Bauenden)

Muss stehen: Instanz, Katalog-Import, Advancing starten mit Vorbelegung,
Vorbelegung bearbeiten, Per Mail senden, Link kopieren/ansehen, Sperren,
Formularseite (Zwischenspeichern, Absenden, erneut absenden), Abgabe-Mail,
Antworten mit Markierung im Reiter, Abschließen.

Darf ein paar Tage später kommen (vor der ersten Abgabe eines Veranstalters
nicht nötig): `export`, „Neuer Link“, „Wieder öffnen“, „Aus dem Event neu
vorbelegen“, Anzeige im Dashboard/Fortschritt.

Zeitplan rückwärts: Bau und Testkopie Di/Mi (17./18.11.), Einspielen nach
„deploy“ spätestens Do 19.11.; bis dahin vom Nutzer SSL, Passwörter in der
`.env` und das erste Konto. Event und Veranstalter für den 05.12. legt der
Planer danach selbst an.

## Zusammenführen und andere Installationen (Entscheidung des Bauenden)

Der Zweig `ce-advancing` geht nach grünen Tests in `main` – eine Codebasis für
alle Installationen. Eingespielt wird jetzt nur ce.vcontrol.eu. ipa bekommt den
Stand mit dem nächsten regulären Einspielen; das Modul ist dort aus (gespeicherte
`modules`). demo.vcontrol.eu läuft zurzeit mit IPA-Control-Code (Zweig eic) und
ist nicht betroffen; wird dort wieder VenueControl eingespielt, ist Advancing
frei (ohne gespeicherte Module) – gewollt, zum Vorführen.

## Dem Bauenden überlassen

Genaue Spalten-/Indexnamen, Aufteilung in Klassen (Vorbild `Daysheets`,
`DaysheetController`, `DaysheetMail`), Gestaltung der Formularseite im Stil der
Daysheet-Seite (`sheet.css`, Farbschema), Texte der Mails und Hinweise,
Validierung je Typ (Zahl ≥ 0, Datum), Länge von Textfeldern.
