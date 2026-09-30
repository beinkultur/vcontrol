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
- `vendor/` und `.env` existieren nur auf dem Server. Nach Änderungen an
  `composer.json` dort `composer install` ausführen und `composer.lock` zurück ins
  Repo holen.
- Deploy: geänderte Dateien einzeln per `scp` auf den vollständigen Zielpfad, danach
  je nach Änderung `php artisan view:clear`, `config:clear` oder `route:clear`, bei
  neuen Migrationen `php artisan migrate --force`.
- Die Server-CLI ist PHP 8.3 (`php`), daneben gibt es `php85`. Code muss unter beiden
  laufen.
- Oberfläche, Texte, Kommentare und Commit-Nachrichten auf Deutsch.
