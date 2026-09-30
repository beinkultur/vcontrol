<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Durchführung abgeschlossen“ und „Event abgeschlossen“ waren in allen Events
 * gleich; seit 30.09.2026 gibt es nur noch „Event abgeschlossen“ (closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('doing_closed');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('doing_closed')->default(false)->after('onsite_contact');
        });
        DB::table('events')->update(['doing_closed' => DB::raw('closed')]);
    }
};
