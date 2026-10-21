<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Durchführung › Check: „Personenlift“ ja/nein (21.10.2026), leer heißt noch nicht beantwortet. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_operations', function (Blueprint $table) {
            $table->boolean('person_lift')->nullable()->after('house_delay');
        });
    }

    public function down(): void
    {
        Schema::table('event_operations', function (Blueprint $table) {
            $table->dropColumn('person_lift');
        });
    }
};
