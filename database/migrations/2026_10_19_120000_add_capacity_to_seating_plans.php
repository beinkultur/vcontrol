<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kapazität je Standard-Saalplan (19.10.2026): Liegt „PAX erwartet“ eines
 * Events darüber, warnt das Event (Event::capacityWarning). Leer = keine Angabe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seating_plans', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('seating_plans', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });
    }
};
