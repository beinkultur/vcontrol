# Vorstufe „Mehrere Venues“: Betrieb abschaltbar, Venue-Name an einer Stelle – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Durchführung › Betrieb samt IPA-Durchführungs-Check wird ein abschaltbares Modul; die Lesestellen des Venue-Namens laufen über eine Klasse – beides ohne sichtbare Änderung für ipa.

**Architecture:** Neuer Fall `Module::Operations` (`betrieb`); `ExecutionCheck`, `EventProgress`, `EventForm`, `EventsTable` und `AccountingDashboard` fragen ihn. Eine Migration ergänzt `betrieb` in gespeicherten Modul-Listen (ipa). `App\Support\VenueIdentity::name()` ersetzt `Setting::lookup(VENUE_NAME) ?: config('app.name')`.

**Tech Stack:** Laravel 13, Filament 5, PHPUnit (SQLite im Speicher), PHP 8.3/8.5.

**Spec:** `docs/superpowers/specs/2026-11-17-mehrere-venues-design.md`, Abschnitte „Vorstufe“ und „Identität“ (Vorstufe vom Nutzer am 17.11.2026 freigegeben: „Vorstufe starten“).

## Global Constraints

- Für ipa (Module gespeichert: buehnenplan,saalplan,gaesteliste,buchhaltung,preislisten) und demo (nichts gespeichert) ändert sich sichtbar nichts.
- Ohne Modul Betrieb: Reiter weg, vergangene Events gelten als vollständig erfasst (nicht unter „Zukünftige“), Fortschritt der Durchführung zählt nur „Event abgeschlossen“, Buchhaltungs-Dashboard ohne Durchführungs-Teil. Gespeichertes bleibt.
- Texte deutsch; Tests in der Testkopie (PHP 8.3 und 8.5), `vc:check-access` gleich, `vc:smoke` fehlerfrei.

## Review Focus

1. Migration auf ipa: gespeicherte Liste bekommt `betrieb` genau einmal, auch beim zweiten Lauf; leere gespeicherte Liste (`''` = alle Module aus) bleibt leer.
2. Event-Liste „Zukünftige“ ohne Modul: SQL-Filter und Tooltip stimmen überein (kein vergangenes Event mit leerem Tooltip in der Liste).
3. Zählerstände je Event: ohne Betrieb nicht mehr im Event, die Seite /zaehler bleibt (eigener Bereich).

---

### Task 1: Modul Betrieb

**Files:** Modify `app/Access/Module.php`, `app/Support/ExecutionCheck.php`, `app/Support/EventProgress.php`, `app/Filament/Resources/Events/Schemas/EventForm.php` (Reiter `betrieb`), `app/Filament/Resources/Accounting/Pages/AccountingDashboard.php`, `tests/Feature/ModulesTest.php` (Listen); Create `database/migrations/2026_11_17_120000_add_operations_module_to_stored_modules.php`, `tests/Feature/OperationsModuleTest.php`.

**Interfaces:** `Module::Operations` (`'betrieb'`, Label „Betrieb“); `ExecutionCheck::missing()`/`missingChecks()` → `[]` und `applyIncomplete()` → keine Treffer (`whereRaw('1 = 0')`), wenn das Modul aus ist.

- [ ] Failing tests: `test_reiter_betrieb_nur_mit_modul` (EditEvent-HTML `data-tab-key="betrieb"` mit/ohne), `test_ohne_betrieb_bleibt_vergangenes_event_nicht_in_zukuenftige` (vergangenes Event ohne Check-Werte: mit Modul in `ListEvents` sichtbar, ohne nicht; `ExecutionCheck::missing()` leer), `test_ohne_betrieb_zaehlt_nur_abgeschlossen` (`EventProgress::phases()['durchfuehrung']` 0 → 100 mit `closed`), `test_migration_ergaenzt_betrieb_in_gespeicherten_modulen` (gespeichert `saalplan` → `saalplan,betrieb`, zweiter Lauf unverändert; `''` bleibt `''`; ohne Eintrag bleibt ohne Eintrag).
- [ ] Testlauf → FAIL.
- [ ] Implementieren; `ModulesTest` um `betrieb` ergänzen.
- [ ] Testlauf → PASS, ganze Suite.
- [ ] Commit „Modul Betrieb: Durchführung › Betrieb und Durchführungs-Check abschaltbar“.

### Task 2: Venue-Name an einer Stelle

**Files:** Create `app/Support/VenueIdentity.php`, `tests/Feature/VenueIdentityTest.php`; Modify die Lesestellen (AppPanelProvider, ManageVenue nur lesend, Advancing-/Daysheet-/GuestListPrintController, CostingPdf, Daysheets, EventIcsFeed, PersonalIcsFeed, ExternSheet, GuestLists, MailIdentity, errors/503).

**Interfaces:** `VenueIdentity::name(): string` = `Setting::lookup(VENUE_NAME)` getrimmt, sonst `config('app.name')`; muss ohne Datenbank (Wartungsseite) auf `config('app.name')` zurückfallen.

- [ ] Failing test `test_name_mit_rueckfall` (gesetzt, leer, ohne Tabelle `settings` → kein Fehler).
- [ ] Testlauf → FAIL.
- [ ] Implementieren, alle Lesestellen umstellen (`grep "Setting::VENUE_NAME"` danach nur noch in Setting, VenueIdentity, ManageVenue-Speichern, Migrationen, Tests).
- [ ] Ganze Suite → PASS (bestehende Tests sichern „keine sichtbare Änderung“).
- [ ] Commit „Venue-Name an einer Stelle (VenueIdentity)“.

### Task 3: Prüfen und Einspielen

- [ ] Suite 8.3/8.5, check-access, smoke (Testkopie mit Demodaten), Doku (AGENTS.md Module, EVENTS.md Betrieb).
- [ ] main per Fast-Forward, push; Einspielen nur nach „deploy“: ce (dort danach Betrieb unter Verwaltung › System aus), ipa nur auf ausdrücklichen Wunsch.
