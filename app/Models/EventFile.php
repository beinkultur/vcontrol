<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Datei an einem Event (oder übergreifend für mehrere). Liegt auf der Disk
 * „local“, also nicht öffentlich – heruntergeladen wird über eine Route mit
 * Rechteprüfung (EventFileController).
 */
#[Fillable(['tag_id', 'event_id', 'title', 'path', 'original_name', 'mime_type', 'size', 'version', 'uploaded_at', 'is_shared', 'hidden_from_externals'])]
class EventFile extends Model
{
    public const DISK = 'local';

    public const MAX_KB = 15 * 1024;

    /** Wie in der PHP-Version; Archive nur für Admins. */
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'dwg', 'dxf', 'txt', 'csv'];

    public const ARCHIVE_EXTENSIONS = ['zip', 'rar', '7z'];

    /** Endungen, die der Browser anzeigt statt herunterzuladen (siehe inlineType()) */
    public const INLINE_TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'txt' => 'text/plain',
    ];

    /** Inhalte, die kein Upload haben darf – egal unter welcher Endung */
    public const BLOCKED_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
        'text/javascript', 'application/javascript', 'text/x-php', 'application/x-httpd-php',
    ];

    protected static function booted(): void
    {
        static::creating(function (EventFile $file): void {
            $user = Auth::user();
            if ($user instanceof User) {
                $file->created_by ??= $user->id;
                $file->created_by_name ??= $user->getFilamentName();
            }
            $file->uploaded_at ??= now();
        });

        static::updating(function (EventFile $file): void {
            $user = Auth::user();
            if ($user instanceof User) {
                $file->updated_by = $user->id;
                $file->updated_by_name = $user->getFilamentName();
            }
        });

        // Mit dem Datensatz verschwindet die Datei auf der Platte.
        static::deleted(function (EventFile $file): void {
            Storage::disk(self::DISK)->delete($file->path);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version' => 'integer',
            'uploaded_at' => 'datetime',
            'is_shared' => 'boolean',
            'hidden_from_externals' => 'boolean',
        ];
    }

    /** @return BelongsTo<EventFileTag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(EventFileTag::class, 'tag_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Events, an die eine übergreifende Datei gehängt ist. @return BelongsToMany<Event, $this> */
    public function linkedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_file_links', 'file_id', 'event_id');
    }

    public function displayName(): string
    {
        return filled($this->title) ? (string) $this->title : $this->original_name;
    }

    /** „Stand: 29.06.2026 14:12 · v2“ wie in der PHP-Version. */
    public function standLabel(): string
    {
        return 'Stand: ' . ($this->uploaded_at?->format('d.m.Y H:i') ?? '–') . ' · v' . $this->version;
    }

    public function sizeLabel(): string
    {
        $bytes = (int) $this->size;

        return match (true) {
            $bytes <= 0 => '–',
            $bytes < 1024 => $bytes . ' B',
            $bytes < 1024 * 1024 => number_format($bytes / 1024, 0, ',', '.') . ' KB',
            default => number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB',
        };
    }

    public function downloadUrl(): string
    {
        return route('event-files.download', $this);
    }

    /**
     * Content-Type für die Anzeige im Browser statt Herunterladen (PDFs, Bilder,
     * Text), fest je Endung. Nur wenn der beim Hochladen am Inhalt erkannte Typ
     * dazu passt – eine „.pdf“ mit HTML darin käme sonst als Webseite vom
     * App-Ursprung (gespeichertes XSS). Alles andere: null = herunterladen.
     */
    public function inlineType(): ?string
    {
        $type = self::INLINE_TYPES[strtolower(pathinfo((string) $this->original_name, PATHINFO_EXTENSION))] ?? null;
        $detected = strtolower(trim(explode(';', (string) $this->mime_type)[0]));

        return $type !== null && $detected === $type ? $type : null;
    }
}
