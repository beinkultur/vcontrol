<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durchführung › Betrieb (30.09./01.10.2026): Bus-Strom als Anzahl der
 * Anschlüsse (bis 5, einzeln abgerechnet) statt ja/nein; Stromzähler Stand
 * Anfang und Ende statt eines eingetippten Verbrauchs (der wird berechnet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_operations', function (Blueprint $table) {
            $table->unsignedTinyInteger('bus_power')->nullable()->change();
            $table->decimal('power_meter_start', 12, 2)->nullable()->after('power_end_ref');
            $table->decimal('power_meter_end', 12, 2)->nullable()->after('power_meter_start');
        });
    }

    public function down(): void
    {
        Schema::table('event_operations', function (Blueprint $table) {
            $table->dropColumn(['power_meter_start', 'power_meter_end']);
            $table->boolean('bus_power')->nullable()->change();
        });
    }
};
