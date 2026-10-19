<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * „Venue“ statt „Halle“ (19.10.2026): Der Platzhalter {halle} in den
 * gespeicherten Vorgaben für Daysheet- und Costing-Mails heißt jetzt {venue}.
 * {halle} wird beim Versand weiter ersetzt (App\Support\Daysheets::fill) –
 * die Umstellung hier ist nur, damit die Texte zur Hilfe passen.
 */
return new class extends Migration
{
    private const KEYS = ['daysheet_subject', 'daysheet_text', 'costing_subject', 'costing_text'];

    public function up(): void
    {
        $this->swap('{halle}', '{venue}');
    }

    public function down(): void
    {
        $this->swap('{venue}', '{halle}');
    }

    private function swap(string $from, string $to): void
    {
        foreach (DB::table('settings')->whereIn('key', self::KEYS)->where('value', 'like', '%' . $from . '%')->get(['key', 'value']) as $setting) {
            DB::table('settings')->where('key', $setting->key)->update(['value' => str_replace($from, $to, (string) $setting->value)]);
        }
    }
};
