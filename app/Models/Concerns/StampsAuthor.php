<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Wer angelegt und zuletzt geändert hat – mit dauerhaft gespeichertem Namen wie
 * in der PHP-Version (RecordMeta): bleibt lesbar, wenn das Konto gelöscht wird.
 * Erwartet die Spalten created_by, created_by_name, updated_by, updated_by_name.
 */
trait StampsAuthor
{
    protected static function bootStampsAuthor(): void
    {
        static::creating(function ($model): void {
            $user = Auth::user();
            if ($user instanceof User) {
                $model->created_by ??= $user->id;
                $model->created_by_name ??= $user->getFilamentName();
            }
        });

        static::updating(function ($model): void {
            $user = Auth::user();
            if ($user instanceof User) {
                $model->updated_by = $user->id;
                $model->updated_by_name = $user->getFilamentName();
            }
        });
    }
}
