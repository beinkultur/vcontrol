<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vorgabe für neue Costings: ausgeschaltet starten, etwa Alternativen wie die Grundmiete bestuhlt
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->boolean('default_active')->default(true)->after('factor_from_pax');
        });
    }

    public function down(): void
    {
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropColumn('default_active');
        });
    }
};
