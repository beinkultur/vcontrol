<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Einstellung dieser Halle als Schlüssel/Wert. */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const VENUE_NAME = 'venue_name';

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

    /** Geheimer Schlüssel in der Adresse, die der KAS-Cronjob für die nächtliche Sicherung aufruft */
    public const BACKUP_CRON_KEY = 'backup_cron_key';

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
