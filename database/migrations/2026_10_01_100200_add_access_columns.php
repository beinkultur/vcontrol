<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rechte-Matrix als JSON: wird immer als Ganzes gelesen und bearbeitet
        Schema::table('roles', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('sort_order');
            $table->json('calendar_permissions')->nullable()->after('permissions');
        });

        // Kalender-Rechte einzelner Benutzer, zusätzlich zu denen ihrer Rollen
        Schema::table('users', function (Blueprint $table) {
            $table->json('calendar_permissions')->nullable()->after('is_active');
        });

        Schema::create('calendars', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->string('name', 120);
            $table->char('color', 7);
            // Wie ein Eintrag bei der Freitermin-Abfrage zählt
            $table->string('freitermin_status', 20)->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendars');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('calendar_permissions'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn(['permissions', 'calendar_permissions']));
    }
};
