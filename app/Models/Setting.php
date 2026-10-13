<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Einstellung dieser Halle als Schlüssel/Wert. */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const VENUE_NAME = 'venue_name';

    /** Pfad des Hallen-Logos (App\Support\VenueLogo), steht auf dem Costing */
    public const VENUE_LOGO = 'venue_logo';

    public const PODEST_INVENTORY = 'podest_inventory';

    public const PODEST_INCLUDED = 'podest_included';

    /** Geheimer Schlüssel des Kalender-Feeds; leer = Feed aus. */
    public const CALENDAR_FEED_TOKEN = 'calendar_feed_token';

    /** Antwortadresse für Mails der Halle (Absender ist no-reply@… aus der Server-Konfiguration) */
    public const MAIL_REPLY_TO = 'mail_reply_to';

    /** Daysheet: Standard-Empfänger (mit Komma getrennt), Betreff und Text der Mail */
    public const DAYSHEET_TO = 'daysheet_to';

    public const DAYSHEET_SUBJECT = 'daysheet_subject';

    public const DAYSHEET_TEXT = 'daysheet_text';

    /** Empfänger neuer Schadensmeldungen (mit Komma getrennt); leer: Rolle „hausmeister“ */
    public const DAMAGE_NOTIFY = 'damage_notify';

    /** Abgeschaltete Bereiche (mit Komma getrennt), nur der System-Admin ändert sie – siehe App\Access\Modules */
    public const DISABLED_AREAS = 'disabled_areas';

    /** Freigegebene Module (mit Komma getrennt), nur der System-Admin ändert sie; ohne Eintrag sind alle frei – siehe App\Access\Modules */
    public const MODULES = 'modules';

    /** Geheimer Schlüssel in der Adresse, die der KAS-Cronjob für die nächtliche Sicherung aufruft */
    public const BACKUP_CRON_KEY = 'backup_cron_key';

    /** Farbschema der Oberfläche (App\Enums\ColorScheme), nur der System-Admin ändert es */
    public const COLOR_SCHEME = 'color_scheme';

    /** Costing (App\Support\CostingSettings): Mail-Vorgaben, wer es sieht, ob Externe und das Daysheet es zeigen */
    public const COSTING_SUBJECT = 'costing_subject';

    public const COSTING_TEXT = 'costing_text';

    public const COSTING_BCC = 'costing_bcc';

    /** Rollen-IDs mit Komma; ohne Eintrag: wer Events bearbeitet oder die Buchhaltung sieht */
    public const COSTING_MENU_ROLES = 'costing_menu_roles';

    public const COSTING_EVENT_ROLES = 'costing_event_roles';

    /** '1' = zeigen; ohne Eintrag verborgen */
    public const COSTING_EXTERN = 'costing_extern';

    public const COSTING_DAYSHEET = 'costing_daysheet';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public static function lookup(string $key, ?string $default = null): ?string
    {
        return self::query()->find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
