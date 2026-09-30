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
  Parameter). Überträgt per rsync und leert die Caches. Neue Migrationen danach
  selbst mit `php artisan migrate --force` ausführen.
- Daten kommen aus der PHP-Version: `php artisan vc:import`, beliebig oft
  wiederholbar. Bis zum Umstieg ist die PHP-Version führend; was hier geändert
  wird, überschreibt der nächste Import.
- Filament-Code gegen `vendor/filament` auf dem Server prüfen, nicht aus dem
  Gedächtnis von Version 3 schreiben: Version 5 hat andere Namespaces
  (`Filament\Schemas\…`, `Filament\Actions\…`).
- Die Server-CLI ist PHP 8.3 (`php`), daneben gibt es `php85`. Code muss unter beiden
  laufen.
- Oberfläche, Texte, Kommentare und Commit-Nachrichten auf Deutsch.
