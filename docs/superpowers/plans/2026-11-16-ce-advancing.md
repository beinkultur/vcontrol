# Advancing-Fragenkatalog und Instanz ce.vcontrol.eu – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Planer schickt dem Veranstalter zu einem Event einen vorausgefüllten Fragenkatalog per Link ohne Konto; der Veranstalter speichert/sendet ab, der Planer sieht die Änderungen. Dazu die Installation ce.vcontrol.eu.

**Architecture:** Neues Modul `Module::Advancing`. Katalog als JSON-Struktur (`advancing_templates`), je Event eine Kopie mit Vorbelegung und Antworten (`event_advancings`). Fachlogik in `App\Support\Advancings` nach dem Vorbild `Daysheets`; Planer-Seite als eingebetteter Relation Manager im Reiter Planung › Advancing; Veranstalter-Seite als klassisches Blade-Formular (POST, CSRF, kein Livewire) über `/advancing/{token}`.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, PHPUnit (SQLite im Speicher), PHP 8.3 und 8.5.

**Spec:** `docs/superpowers/specs/2026-11-16-ce-advancing-design.md` (freigegeben 16.11.2026)

## Global Constraints

- Oberfläche, Texte, Kommentare, Commits auf Deutsch; „Venue“, nie „Halle“.
- Keine personenbezogenen Daten im Git (keine Namen/Mails/Telefonnummern aus dem Google-Formular, auch nicht in Tests – dort `example.org`).
- Lokal gibt es kein PHP: Tests laufen in einer abgeschotteten Testkopie auf dem Server (`/www/htdocs/w0219ff1/.vc-test-<zufall>`, `chmod 700`, vendor per `cp -al` aus ipa, `.env` mit `APP_ENV=testing`, `APP_NAME=VenueControl`, `APP_URL=https://ce.vcontrol.eu`), je `php artisan test` und `php85 artisan test`. Ordner am Ende löschen.
- Jede gespeicherte Änderung über Eloquent (Audit automatisch); neue Tabellen in `AuditPresenter::SUBJECTS`, `SUBJECT_AREAS` (Area::Events), Felder in `FIELDS`; Schlüssel in `Audit::SECRETS`.
- Jede Stelle des Moduls fragt `Module::Advancing->isEnabled()`; ohne Freigabe: kein Reiter, Routen 404.
- Rechte: sehen = Events lesen, alles Ändernde = Events bearbeiten (`EventResource::canEdit`).
- Der Link gilt bis `Daysheets::validUntil($event)` (23:59 am Tag nach dem letzten Event-Tag), Schlüssel 48 Zeichen `[A-Za-z0-9]`, gesucht über SHA-256-Hash.
- Veranstalter-Seite: Header `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private`; Route `throttle:60,1`.
- Nach jeder Aufgabe: Tests grün; am Ende `vc:check-access` unverändert, `vc:smoke` fehlerfrei.

## Review Focus

1. **Zwei Leute füllen denselben Link gleichzeitig aus** – wer zuletzt speichert, darf die Antworten des anderen nicht mit dessen alten Werten überschreiben. Erwartung: nur Felder, die diese Person gegenüber dem beim Laden gezeigten Wert geändert hat, werden übernommen (Task 3, `test_gleichzeitiges_speichern_ueberschreibt_nichts`).
2. **HTML/Skript in Antworten** (`<script>`, `&`) – überall maskiert: Formularseite, Planer-Ansicht, Mail (Task 3, `test_antworten_werden_maskiert`).
3. **Fremde Event-Daten auf der Seite** – Buchungsnotizen, WLAN-Passwort, Veranstalter-Kundennummer dürfen nicht im HTML stehen (Task 3, `test_seite_zeigt_keine_fremden_event_daten`).
4. **Gesperrte Fragen per handgebautem POST** – `locked`-Fragen, Infotexte und unbekannte Schlüssel werden ignoriert (Task 3, `test_gesperrte_und_unbekannte_felder_werden_ignoriert`).
5. **Katalog wird neu importiert** – laufende Advancings behalten ihre Fragen und Antworten (Task 1, `test_neuer_import_aendert_laufende_advancings_nicht`).

---

### Task 1: Modul, Datenmodell, Katalog-Import

**Files:**
- Modify: `app/Access/Module.php` (Fall `Advancing = 'advancing'`, Label „Advancing“, Beschreibung: „Fragenkatalog an den Veranstalter je Event: vorausgefüllt unter Planung › Advancing, Link ohne Konto per Mail, Antworten mit Markierung der Änderungen. Kataloge per `php artisan vc:advancing-catalog import`.“)
- Create: `database/migrations/2026_11_17_100000_create_advancing_tables.php`
- Create: `app/Models/AdvancingTemplate.php`, `app/Models/EventAdvancing.php`
- Modify: `app/Models/Event.php` (`advancing(): HasOne` für die Fachlogik, `advancings(): HasMany` nur für den Relation Manager – Unique-Index hält es bei einem)
- Create: `app/Support/AdvancingCatalog.php`
- Create: `app/Console/Commands/AdvancingCatalogCommand.php`
- Create: `database/advancing/lkh-arena-kultur.json` (Entwurf liegt im Scratchpad der Sitzung: `form/lkh-arena-kultur.json`; Schlüssel vor dem Übernehmen kürzen/lesbar machen, z. B. `tourleitung`, `tourleitung_email`, `tourleitung_mobil`, `produktion`, `produktion_email`, `produktion_mobil`)
- Modify: `app/Support/Audit.php` (`SECRETS` + `'token'`), `app/Support/AuditPresenter.php`
- Test: `tests/Feature/AdvancingCatalogTest.php`

**Interfaces:**
- Produces:
  - Tabelle `advancing_templates`: `id`, `name` string(120) unique, `intro` text null, `structure` json, `is_active` bool default true, `sort_order` int default 0, timestamps.
  - Tabelle `event_advancings`: `id`, `event_id` FK unique cascadeOnDelete, `advancing_template_id` FK null nullOnDelete, `template_name` string(120), `intro` text null, `structure` json, `prefill` json null, `answers` json null, `token_hash` string(64) null unique, `token` text null (Cast `encrypted`), `expires_at`/`revoked_at`/`sent_at`/`first_opened_at`/`last_saved_at`/`submitted_at`/`closed_at` datetime null, `submit_count` unsigned int default 0, `recipients_to`/`recipients_bcc` json null, `sent_by` FK users null nullOnDelete, `closed_by_name` string(120) null, StampsAuthor-Spalten, timestamps.
  - `AdvancingTemplate` (Casts `structure` array, `is_active` bool; Scope `active()` nach `sort_order`, `name`).
  - `EventAdvancing` (`#[Hidden(['token_hash','token'])]`, StampsAuthor, Casts; `event(): BelongsTo`; `static newToken(): string` = `Daysheet::newToken()`; `static findByToken(string $token): ?self` wie `Daysheet::findByToken`; `isRevoked()`, `isExpired()` (true, wenn `expires_at` null oder vorbei), `isClosed()`, `isLinkValid(): bool` = gesendet && !revoked && !expired; `link(): ?string` = `route('advancing.show', $this->token)` wenn Token da; `statusLabel(): string` ∈ `Entwurf|versendet|geöffnet|abgegeben|abgeschlossen|gesperrt|abgelaufen` (Vorrang: abgeschlossen, gesperrt, abgelaufen, abgegeben, geöffnet, versendet, Entwurf); `questions(): array<string, array>` = alle Fragen der Struktur nach `key`, ohne `info`).
  - `AdvancingCatalog::TYPES = ['text','textarea','number','date','choice','yes_no','person','info']`; `AdvancingCatalog::BINDINGS` = die 11 Bindungen aus der Spec; `AdvancingCatalog::errors(array $structure): list<string>` (leer = gültig; prüft: Liste von Abschnitten mit `items`; jede Frage hat `key` `^[a-z0-9_]{1,60}$`, eindeutig; `type` bekannt; `choice` hat nicht leere `options`; `binding` bekannt; `info` hat `label` oder `text`); `AdvancingCatalog::import(array $data): AdvancingTemplate` (legt an oder ersetzt nach `name`; wirft `InvalidArgumentException` mit den Fehlern).
  - Befehl `vc:advancing-catalog import {datei}` (Ausgabe „Katalog „Kultur“ importiert: 46 Fragen, 4 Infotexte.“; Fehler → Exit 1 mit Liste).

- [ ] **Step 1: Testkopie anlegen** – Skript `scratchpad/vc-test.sh` (rsync ohne `/vendor/ /storage/ /bootstrap/cache/ .git/ .env`, danach `chmod 700`, beim ersten Mal `cp -al ../ipa.vcontrol.eu/vendor`, storage-Ordner, `.env` wie in Global Constraints). Prüfen: `php artisan test` grün auf dem Ausgangsstand.
- [ ] **Step 2: Failing tests schreiben** – `test_lkh_katalog_ist_gueltig_und_ohne_personen` (Datei importieren → 46 Fragen, 4 Infotexte; `grep` auf `@`/Telefon-Muster `\d{4,}\s?\d{3,}` in der Datei → kein Treffer außer Preisen), `test_ungueltige_struktur_wird_abgelehnt` (doppelter key, Typ `upload`, `choice` ohne options, Bindung `event.foo` → je eine Fehlermeldung, nichts gespeichert), `test_neuer_import_ersetzt_gleichnamigen_katalog`, `test_neuer_import_aendert_laufende_advancings_nicht` (EventAdvancing mit Kopie anlegen, Katalog mit anderer Frage neu importieren → `structure` und `answers` des Advancings unverändert), `test_befehl_importiert_und_meldet_fehler` (`artisan('vc:advancing-catalog', ['action' => 'import', 'file' => …])->assertExitCode(0)` / ungültige Datei → 1), `test_modul_advancing_ist_auf_ipa_aus` (`Setting::put(Setting::MODULES, 'saalplan')` → `Module::Advancing->isEnabled()` false; ohne Eintrag true), `test_schluessel_im_audit_maskiert` (EventAdvancing mit token anlegen → AuditLog `new_values['token']` und `['token_hash']` = `***`).
- [ ] **Step 3: Testlauf → FAIL** (`php artisan test --filter=AdvancingCatalogTest`).
- [ ] **Step 4: Implementieren** (Migration, Modelle, `AdvancingCatalog`, Befehl, Katalog-Datei, Audit/AuditPresenter-Einträge: Subjects `advancing_templates` „Advancing-Katalog“ und `event_advancings` „Advancing“, Felder mit deutschen Namen).
- [ ] **Step 5: Testlauf → PASS**, dazu die ganze Suite.
- [ ] **Step 6: Commit** „Advancing: Modul, Datenmodell und Katalog-Import (LKH Arena Kultur)“.

### Task 2: Fachlogik – starten, vorbelegen, Personen, versenden

**Files:**
- Create: `app/Support/Advancings.php`
- Modify: `app/Mail/DaysheetMail.php` (neuer letzter Parameter `public string $buttonLabel = 'Daysheet öffnen'`), `resources/views/mail/daysheet-html.blade.php` (Schaltfläche und Subcopy mit `{{ $buttonLabel }}`)
- Test: `tests/Feature/AdvancingTest.php`

**Interfaces:**
- Consumes: Task 1 (Modelle, `AdvancingCatalog`).
- Produces (`App\Support\Advancings`, `final class`):
  - `DEFAULT_SUBJECT = 'Advancing {event} – {datum}'`; `DEFAULT_TEXT` = „Hallo,\n\nzur Vorbereitung von {event} am {datum} in der {venue} bitten wir euch, unseren Fragenkatalog auszufüllen. Was wir schon wissen, ist eingetragen – bitte prüfen und ergänzen:\n{link}\n\nDer Link ist bis {gueltig_bis} gültig; ihr könnt zwischendurch speichern und später weitermachen.\n\nViele Grüße\n{venue}“.
  - `start(Event $event, AdvancingTemplate $template): EventAdvancing` (Kopie von name/intro/structure, `prefill` = `prefillFromEvent()`; wirft `InvalidArgumentException`, wenn schon eins existiert).
  - `prefillFromEvent(Event $event, array $structure): array<string,string>` (nur Fragen mit `binding`, leere Werte weglassen; `event.date` → `Y-m-d` des ersten Tags; Zeiten `H:i` → „11:30 Uhr“; `onsite.name` = Vorname + Name).
  - `personOptions(): array<string,string>` (`'tba' => 'tba.'`, dann aktive Benutzer mit `account_type->hasPositions()` als `user:ID`, dann nicht archivierte Kontakte als `contact:ID`; Label wie `personLabel`).
  - `personLabel(?string $ref): string` (`Name | E-Mail | Telefon`, leere Teile weg, Trenner „  |  “; unbekannt/gelöscht/`tba`/leer → „tba.“).
  - `value(EventAdvancing $a, string $key): ?string` = `answers[key] ?? prefill[key]`.
  - `changedKeys(EventAdvancing $a): list<string>` = Schlüssel in `answers`, deren Wert ≠ aktuelle Vorbelegung.
  - `display(array $question, ?string $value): string` (Datum `d.m.Y`, `yes_no` „ja/nein“, `person` → `personLabel`, sonst Text; leer → „–“).
  - `send(EventAdvancing $a, array $to, array $bcc, string $subject, string $body, ?User $sender): EventAdvancing` (wie `Daysheets::send`: Empfänger säubern, `{link}` anhängen falls fehlt, Platzhalter mit `Daysheets::fill` ersetzen; ohne Datum/vorbei → `InvalidArgumentException` mit den Daysheet-Texten sinngemäß; Token nur erzeugen, wenn keiner da ist (erneutes Senden = derselbe Link); setzt `expires_at = Daysheets::validUntil()`, `sent_at`, `sent_by`, `recipients_*`; in einer Transaktion mit `Mail::send(new DaysheetMail(…, buttonLabel: 'Fragenkatalog öffnen'))` – scheitert die Mail, bleibt nichts gespeichert).
  - `defaults(EventAdvancing $a): array{to: list<string>, bcc: list<string>, subject: string, body: string}` (An = E-Mail des Ansprechpartners vor Ort, wenn gültig; BCC leer).

- [ ] **Step 1: Failing tests** – `test_start_kopiert_katalog_und_belegt_aus_dem_event_vor` (Event mit Titel, starts_at 2026-12-05, schedule get_in 11:30, admission 17:30, onsite Vorname/Name/Mail/Telefon → prefill `veranstaltung`, `datum` = `2026-12-05`, `get_in` = `11:30 Uhr`, `einlass` = `17:30 Uhr`, `tourleitung` = „Vor Name“ …; Frage ohne Wert fehlt), `test_zweites_advancing_je_event_geht_nicht`, `test_personen_auswahl_und_anzeige` (Benutzer Kontotyp venue_employee + Kontakt; Gewerk-Konto und archivierter Kontakt fehlen; Label „Kontakt | k@example.org | 0151 000“; nach Löschen des Kontakts „tba.“), `test_versand_mit_link_und_gueltigkeit` (Mail an An, Link `/advancing/[A-Za-z0-9]{48}` im Text, Schaltfläche „Fragenkatalog öffnen“, `expires_at` 06.12. 23:59, `token_hash` gesetzt; zweites Senden → gleicher Link), `test_ohne_datum_oder_vorbei_kein_versand`, `test_geaenderte_schluessel_und_anzeige`, `test_daysheet_mail_unveraendert` (bestehende Daysheet-Tests laufen weiter grün – nur ausführen).
- [ ] **Step 2: Testlauf → FAIL.**
- [ ] **Step 3: Implementieren.**
- [ ] **Step 4: Testlauf → PASS** (inkl. `DaysheetTest`).
- [ ] **Step 5: Commit** „Advancing: starten, vorbelegen, Personen und Versand“.

### Task 3: Seite für den Veranstalter

**Files:**
- Create: `app/Http/Controllers/AdvancingController.php`
- Modify: `routes/web.php` (in der `throttle:60,1`-Gruppe: `GET /advancing/{token}` → `show`, `POST /advancing/{token}` → `update`, Namen `advancing.show`/`advancing.update`, `where('token', '[A-Za-z0-9]{48}')`)
- Create: `resources/views/advancing.blade.php`, `resources/views/advancing-closed.blade.php` (ungültig/gesperrt/abgelaufen, 410)
- Create: `app/Mail/AdvancingSubmittedMail.php`, `resources/views/mail/advancing-submitted.blade.php` (Markdown)
- Modify: `app/Support/Audit.php` (`public static function as(string $name, callable $callback): mixed` – Name für Einträge ohne angemeldeten Benutzer)
- Modify: `app/Support/Advancings.php` (`save()`, `submit()`)
- Test: `tests/Feature/AdvancingFormTest.php`

**Interfaces:**
- Consumes: Task 1/2.
- Produces:
  - `Advancings::save(EventAdvancing $a, array $posted, array $shown): list<string>` – `$posted`/`$shown` sind `key => string` aus `a[…]` bzw. versteckten `o[…]`. Übernommen werden nur Fragen, die nicht `locked`, nicht `info`, bekannt und `$posted[k] !== $shown[k]` (getrimmt) sind; Validierung je Typ (`number` ganze Zahl 0–9999, `date` `Y-m-d`, `yes_no` ∈ ja/nein, `choice` ∈ options, `text` ≤ 500, `textarea` ≤ 5000) → Rückgabe Fehler je key (dann nichts gespeichert). Gespeichert: Wert gleich aktueller Vorbelegung → Eintrag entfernen, sonst setzen. `last_saved_at = now()`. Alles in `Audit::as('Veranstalter (Advancing-Link)', …)`.
  - `Advancings::submit(EventAdvancing $a): list<string>` – fehlende Pflichtfragen (nicht `locked`) als Fehler, sonst `submitted_at`, `submit_count++`, `AdvancingSubmittedMail` an `sent_by` (aktiv, gültige Mail) und `MailIdentity::replyTo()`, ohne Doppelte; Mailfehler → `report()`, Abgabe bleibt.
  - Controller: `show(string $token)` (404 ohne Modul/unbekannt; 410-Seite bei gesperrt/abgelaufen; setzt `first_opened_at` beim ersten Aufruf; abgeschlossen → alles nur lesen), `update(Request $request, string $token)` (Feld `aktion` ∈ `speichern|absenden`; abgeschlossen/ungültig → 410; danach Redirect auf `show` mit Flash „Gespeichert.“ bzw. „Abgeschickt – danke! Ihr könnt bis {gültig bis} weiter ändern und erneut absenden.“; Fehler → zurück mit Eingaben und Meldungen je Feld).
  - Seite: Kopf mit Logo (`VenueLogo::fitted(200, 64)`), Venue-Name, `template_name`, Event-Titel, Datum (Wochentag), `intro`; je Abschnitt Überschrift + `text`; `info` als Kasten; `locked` als Text (`Advancings::display`); sonst Eingabe (`number` mit `inputmode="numeric"`, `date` als `type=date`, `yes_no`/`choice` als Auswahl, `textarea`), Pflicht mit „*“; versteckt `o[key]` mit dem gezeigten Wert; Knöpfe „Zwischenspeichern“ und „Absenden“; Fuß wie Daysheet. Stil: `sheet.css` + Farbschema wie `daysheet.blade.php`, handytauglich (eine Spalte unter 640 px).

- [ ] **Step 1: Failing tests** – `test_seite_zeigt_vorbelegung_und_venue` (Status 200, Header aus Global Constraints, „LKH Arena“, Event-Titel, „11:30 Uhr“ im Feld, gesperrte Personenfrage als Text), `test_seite_zeigt_keine_fremden_event_daten` (Event mit `booking_notes='GEHEIM-NOTIZ'`, `wlan_password='GEHEIM-WLAN'`, Veranstalter `customer_no='K-GEHEIM'` → nicht im HTML), `test_unbekannt_404_gesperrt_und_abgelaufen_410`, `test_modul_aus_404`, `test_erster_aufruf_setzt_geoeffnet`, `test_speichern_merkt_nur_abweichungen` (Feld geändert → in answers; Feld mit Vorbelegung unverändert → nicht in answers; später Vorbelegung geändert → Seite zeigt neue Vorbelegung), `test_gleichzeitiges_speichern_ueberschreibt_nichts` (A und B laden; A ändert `busse`; B ändert `pkw` mit altem `o[busse]` → beide Antworten stehen), `test_gesperrte_und_unbekannte_felder_werden_ignoriert`, `test_pflichtfelder_nur_beim_absenden`, `test_absenden_zaehlt_und_mailt_planer_und_venue` (`Mail::fake`, `AdvancingSubmittedMail` an Sender und Antwortadresse, `submit_count` 2 nach zweimal), `test_abgeschlossen_nur_lesen_und_post_410`, `test_antworten_werden_maskiert` (`<script>x</script>` gespeichert → auf der Seite und in der Mail maskiert), `test_audit_nennt_veranstalter_link` (AuditLog `user_name` = „Veranstalter (Advancing-Link)“), `test_falscher_csrf_wird_abgelehnt` (ohne `withoutMiddleware`, ohne Token → 419).
- [ ] **Step 2: Testlauf → FAIL.**
- [ ] **Step 3: Implementieren.**
- [ ] **Step 4: Testlauf → PASS.**
- [ ] **Step 5: Seite ansehen** – in der Testkopie HTML rendern (Skript wie in der Memory „VenueControl: Einspielen braucht Freigabe“), per WKWebView in 390 px und 1200 px fotografieren, Bilder prüfen.
- [ ] **Step 6: Commit** „Advancing: Formularseite für den Veranstalter mit Zwischenspeichern und Absenden“.

### Task 4: Reiter Planung › Advancing

**Files:**
- Create: `app/Filament/Resources/Events/RelationManagers/AdvancingRelationManager.php` (Beziehung `advancings`)
- Create: `app/Policies/EventAdvancingPolicy.php` (extends `AreaPolicy`, `Area::Events`; `viewAny` zusätzlich `Module::Advancing->isEnabled()`)
- Create: `app/Filament/Support/AdvancingFields.php` (Formular-Schema aus der Struktur)
- Modify: `app/Filament/Resources/Events/Schemas/EventForm.php` (nach „Kontakte“: `Module::Advancing->isEnabled() ? self::tab('Advancing', 'advancing')->schema([self::embedded(AdvancingRelationManager::class)]) : null`)
- Test: `tests/Feature/AdvancingWorkspaceTest.php`

**Interfaces:**
- Consumes: Task 1–3.
- Produces:
  - `AdvancingFields::schema(EventAdvancing $a, bool $editable): array` – je Abschnitt `Section` (Titel, `description` = `text`), `info` als `Placeholder`/`TextEntry`, Fragen als Feld `prefill.<key>` (Typ-Zuordnung wie Formularseite, `person` = `Select` mit `Advancings::personOptions()`), bei Änderung durch den Veranstalter `->hint('Veranstalter: ' . display)` mit `->hintColor('warning')` und `->hintIcon(Heroicon::OutlinedExclamationTriangle)`.
  - Tabelle: eine Zeile – Katalog, Status (`statusLabel`, Badge), Versendet (am · an), Gültig bis, Abgegeben (am, n×), Geändert (`count(changedKeys)`).
  - Kopf-Aktion `startAdvancing` (sichtbar ohne Advancing und mit `canEdit`): Auswahl aktiver Kataloge (Vorauswahl, wenn nur einer) → `Advancings::start`.
  - Zeilen-Aktionen: `edit` „Öffnen“ (SlideOver, Felder bearbeitbar nur mit `canEdit`, speichert `prefill`), `send` „Per Mail senden“ (wie DaysheetActions: An/BCC/Betreff/Text, `Advancings::defaults`; Hinweis auf Gültigkeit), `copyLink` „Link“ (Modal mit schreibgeschütztem, kopierbarem Feld; nur `canEdit`, nur mit Token), `open` „Als Veranstalter ansehen“ (URL, neuer Tab; nur `canEdit`), `revoke`/`unrevoke`, `close` „Abschließen“ (setzt `closed_at`, `closed_by_name`).
  - Leerer Zustand: „Noch kein Advancing“ / „Katalog wählen und starten – was im Event schon steht, ist vorbelegt.“

- [ ] **Step 1: Failing tests** – `test_reiter_nur_mit_modul` (Modul aus → `EditEvent` rendert ohne „Advancing“; an → mit), `test_planer_startet_belegt_vor_und_versendet` (Livewire `callTableAction`/`callAction` mit `ownerRecord`; Mail versendet), `test_planer_aendert_vorbelegung_und_sieht_aenderungen_des_veranstalters` (answers vorhanden → Hinweis „Veranstalter: …“ im Formular), `test_leserolle_sieht_aber_aendert_nicht` (Rolle `events=read`: Tabelle sichtbar, Aktionen start/send/copyLink/edit-speichern nicht verfügbar), `test_link_kopieren_nur_fuer_bearbeiter`, `test_sperren_und_abschliessen`.
- [ ] **Step 2: Testlauf → FAIL.**
- [ ] **Step 3: Implementieren.**
- [ ] **Step 4: Testlauf → PASS**, ganze Suite, `php artisan vc:check-access` (unverändert gegenüber vorher), `php artisan vc:smoke` mit `vc:demo-data`-SQLite in der Testkopie fehlerfrei.
- [ ] **Step 5: Commit** „Advancing: Reiter Planung › Advancing mit Start, Vorbelegung, Versand und Rücklauf“.

### Task 5: Doku, Prüfung, Zusammenführen

**Files:**
- Modify: `docs/EVENTS.md` (Abschnitt „Advancing“: Datenmodell, Regeln, Befehl), `AGENTS.md` (Modulliste in „Module“ um Advancing ergänzen)

- [ ] **Step 1:** Doku schreiben.
- [ ] **Step 2:** Ganze Suite PHP 8.3 und 8.5 in der Testkopie, `vc:check-access`, `vc:smoke`.
- [ ] **Step 3:** Prüfung durch einen frischen Prüfer (ganzer Zweig, Schwerpunkt Review Focus und Rechte), Befunde beheben.
- [ ] **Step 4:** Commit, `main` per Fast-Forward auf `ce-advancing`, nach GitHub pushen (nur `main` von beinkultur/vcontrol). Testkopie löschen.

### Task 6: Instanz ce.vcontrol.eu (erst nach „deploy“ des Nutzers)

- [ ] **Step 1:** Erstübertragung per rsync wie `deploy.sh` (Excludes übernehmen), Wurzel-`.htaccess` wie demo.vcontrol.eu, `umask 022; composer install --no-dev`, `.env` aus `.env.example`: `APP_NAME=VenueControl`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://ce.vcontrol.eu`, neuer `APP_KEY`, `DB_DATABASE=d0490195`, `DB_USERNAME=d0490195`, Mail wie ipa ohne Passwort; `chmod 600 .env`. Nutzer trägt `DB_PASSWORD`, `MAIL_PASSWORD` ein.
- [ ] **Step 2:** `php artisan migrate --force`, `php artisan filament:assets` (umask 022), `vc:advancing-catalog import database/advancing/lkh-arena-kultur.json`.
- [ ] **Step 3:** Nutzer: `php artisan vc:system-admin <mail>`.
- [ ] **Step 4:** Einstellungen (nach Rückfrage per tinker oder Nutzer im Panel): Venue „LKH Arena“, Antwortadresse, Logo, Module `saalplan,advancing`, abgeschaltete Bereiche `anfragen,zaehler,codes,schichten,admin_schichtplanung,schichten_extern,protokolle,events_operations,admin_inventar,admin_gewerke`, Farbschema VC Basic.
- [ ] **Step 5:** Prüfen: Login 200, `/css/filament/filament/app.css` 200, `php artisan test`, `vc:check-access`, `vc:smoke`. REVISION gesetzt.
- [ ] **Step 6:** Notizen aktualisieren (Notizordner, Memory). Offene Nutzer-Schritte nennen: Sicherung (Nextcloud-Ablage + KAS-Cronjob), Cockpit-Eintrag.
