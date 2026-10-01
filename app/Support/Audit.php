<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Änderungsprotokoll wie AuditLogger der PHP-Version. Jede Änderung über
 * Eloquent landet hier automatisch (Listener im AppServiceProvider): anlegen,
 * ändern, löschen – mit den Werten vorher und nachher. Schreibzugriffe ohne
 * Eloquent (Pivot-Tabellen, zusammengesetzte Schlüssel) melden sich über record().
 *
 * Passwörter und der Schlüssel des Kalender-Feeds erscheinen nur als „***“,
 * Unterschriften als „[Unterschrift]“.
 */
final class Audit
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const IMPORTED = 'imported';

    /** Keine inhaltliche Änderung */
    private const IGNORED = ['id', 'created_at', 'updated_at', 'created_by', 'created_by_name', 'updated_by', 'updated_by_name', 'remember_token'];

    private const SECRETS = ['password', 'wlan_password'];

    private const SIGNATURES = ['signature', 'house_rep_signature', 'promoter_rep_signature'];

    private static int $paused = 0;

    /** Aus einem Eloquent-Ereignis (created, updated, deleted) */
    public static function model(string $action, Model $model): void
    {
        if (self::$paused > 0 || $model instanceof AuditLog) {
            return;
        }

        $old = null;
        $new = null;
        if ($action === self::UPDATED) {
            $changes = Arr::except($model->getChanges(), self::IGNORED);
            if ($changes === []) {
                return;
            }
            $new = $changes;
            $old = array_map(fn (string $key): mixed => $model->getRawOriginal($key), array_combine(array_keys($changes), array_keys($changes)));
        } elseif ($action === self::CREATED) {
            $new = self::present($model->getAttributes());
        } else {
            $old = self::present($model->getAttributes());
        }

        self::write(
            subject: $model->getTable(),
            action: $action,
            old: $old === null ? null : self::clean($model, $old),
            new: $new === null ? null : self::clean($model, $new),
            key: self::key($model),
            label: self::label($model),
            eventId: self::eventId($model),
        );
    }

    /**
     * Eintrag von Hand, für Schreibzugriffe ohne Eloquent-Modell.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(string $subject, string $action, ?array $old, ?array $new, ?string $key = null, ?string $label = null, ?int $eventId = null): void
    {
        if (self::$paused > 0) {
            return;
        }
        self::write($subject, $action, $old, $new, $key, $label, $eventId);
    }

    /**
     * Ohne Protokoll ausführen – nur für Vorgänge, die selbst einen Eintrag schreiben.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function quietly(callable $callback): mixed
    {
        self::$paused++;
        try {
            return $callback();
        } finally {
            self::$paused--;
        }
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private static function write(string $subject, string $action, ?array $old, ?array $new, ?string $key, ?string $label, ?int $eventId): void
    {
        $user = Auth::user();
        $http = !app()->runningInConsole();

        try {
            AuditLog::query()->create([
                'user_id' => $user instanceof User ? $user->getKey() : null,
                // Spalte 120 Zeichen – ein längerer Name ließe sonst jeden Eintrag dieses Benutzers scheitern
                'user_name' => $user instanceof User ? Str::limit($user->getFilamentName(), 120, '') : ($http ? null : 'System'),
                'subject' => $subject,
                'subject_key' => $key,
                'subject_label' => $label !== null ? Str::limit($label, 195) : null,
                'event_id' => $eventId,
                'action' => $action,
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => $http ? request()->ip() : null,
                'user_agent' => $http ? Str::limit((string) request()->userAgent(), 250, '') : null,
            ]);
        } catch (Throwable $e) {
            // Das Protokoll darf keine Änderung verhindern – aber auffallen soll es
            report($e);
        }
    }

    /**
     * Werte für das Protokoll: JSON-Spalten als Liste/Objekt, Geheimes maskiert.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function clean(Model $model, array $values): array
    {
        $casts = $model->getCasts();
        foreach ($values as $key => $value) {
            $cast = strtolower((string) ($casts[$key] ?? ''));
            if (is_string($value) && (str_contains($cast, 'array') || str_contains($cast, 'json') || str_contains($cast, 'collection'))) {
                $values[$key] = json_decode($value, true) ?? $value;
            }
            if (in_array($key, self::SECRETS, true) && filled($value)) {
                $values[$key] = '***';
            }
            if (in_array($key, self::SIGNATURES, true) && filled($value)) {
                $values[$key] = '[Unterschrift]';
            }
        }
        if ($model instanceof Setting && $model->getAttribute('key') === Setting::CALENDAR_FEED_TOKEN && array_key_exists('value', $values)) {
            $values['value'] = filled($values['value']) ? '***' : null;
        }

        return $values;
    }

    /**
     * Beim Anlegen und Löschen: nur ausgefüllte Felder, ohne Verwaltungsspalten.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function present(array $attributes): array
    {
        return array_filter(Arr::except($attributes, self::IGNORED), fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function key(Model $model): ?string
    {
        if ($model instanceof Pivot) {
            return $model->getAttribute($model->getForeignKey()) . ':' . $model->getAttribute($model->getRelatedKey());
        }
        $key = $model->getKey();

        return $key === null ? null : (string) $key;
    }

    /** Bezeichnung zum Zeitpunkt der Änderung: Name, Titel o. Ä., sonst das Event. */
    private static function label(Model $model): ?string
    {
        if (method_exists($model, 'auditLabel')) {
            return $model->auditLabel();
        }
        $attributes = $model->getAttributes();
        $name = trim(($attributes['first_name'] ?? '') . ' ' . ($attributes['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        foreach (['title', 'name', 'ordered_from', 'handed_to', 'subject', 'code', 'key', 'description', 'item_name', 'article_name'] as $attribute) {
            $value = $attributes[$attribute] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return Str::limit(trim((string) $value), 80);
            }
        }
        $eventId = $model instanceof Event ? null : self::eventId($model);

        return $eventId === null ? null : Event::query()->whereKey($eventId)->value('title');
    }

    private static function eventId(Model $model): ?int
    {
        if ($model instanceof Event) {
            return (int) $model->getKey();
        }
        if (method_exists($model, 'auditEventId')) {
            return $model->auditEventId();
        }
        $eventId = $model->getAttributes()['event_id'] ?? null;

        return $eventId === null ? null : (int) $eventId;
    }
}
