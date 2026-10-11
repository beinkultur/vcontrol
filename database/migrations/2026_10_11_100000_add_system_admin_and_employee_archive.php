<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // System-Admin (Betreiber von VenueControl) über dem Admin der Halle: dazu
        // Module, Rollen & Rechte und die eigenen Konten. Die Rolle legt
        // `php artisan vc:system-admin` an.
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system_admin')->default(false)->after('is_super');
        });

        // Mitarbeiter wie Gewerke archivieren; löschen nur, solange nichts darauf verweist
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('positions');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('is_archived');
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_system_admin');
        });
    }
};
