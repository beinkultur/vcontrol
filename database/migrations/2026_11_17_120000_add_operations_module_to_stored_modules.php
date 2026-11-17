<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Neues Modul „Betrieb“ (Durchführung › Betrieb und Durchführungs-Check,
 * 17.11.2026). Wer seine Module schon gespeichert hat (ipa.vcontrol.eu), hätte es
 * sonst plötzlich nicht mehr – deshalb kommt es dort dazu, wie bisher vorhanden.
 * Ohne gespeicherte Liste sind ohnehin alle Module frei; eine bewusst leere Liste
 * (alle Module aus) bleibt leer. Mehrfach lauffähig.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stored = Setting::lookup(Setting::MODULES);
        if ($stored === null || trim($stored) === '') {
            return;
        }
        $modules = array_values(array_filter(array_map('trim', explode(',', $stored)), fn (string $m): bool => $m !== ''));
        if (!in_array('betrieb', $modules, true)) {
            $modules[] = 'betrieb';
            Setting::put(Setting::MODULES, implode(',', $modules));
        }
    }

    public function down(): void
    {
        $stored = Setting::lookup(Setting::MODULES);
        if ($stored === null) {
            return;
        }
        $modules = array_values(array_filter(array_map('trim', explode(',', $stored)), fn (string $m): bool => $m !== '' && $m !== 'betrieb'));
        Setting::put(Setting::MODULES, implode(',', $modules));
    }
};
