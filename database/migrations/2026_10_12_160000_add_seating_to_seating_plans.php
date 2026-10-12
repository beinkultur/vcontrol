<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bestuhlung des Standard-Saalplans (Werte der Feldoption „Bestuhlung“), wie die Bereiche
        Schema::table('seating_plans', function (Blueprint $table) {
            $table->json('seating')->nullable()->after('areas');
        });
    }

    public function down(): void
    {
        Schema::table('seating_plans', function (Blueprint $table) {
            $table->dropColumn('seating');
        });
    }
};
