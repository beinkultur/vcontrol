<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Standard-Ansicht des Kalenders (month, week, day, 4weeks), im Profil gewählt
            $table->string('calendar_view', 10)->nullable()->after('calendar_permissions');
            // Persönlicher ICS-Feed: Schlüssel verschlüsselt (zum Anzeigen im Profil),
            // gesucht wird über den SHA-256-Hash
            $table->text('calendar_token')->nullable()->after('calendar_view');
            $table->string('calendar_token_hash', 64)->nullable()->unique()->after('calendar_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token_hash']);
            $table->dropColumn(['calendar_view', 'calendar_token', 'calendar_token_hash']);
        });
    }
};
