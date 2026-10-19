<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mehrere Dateien auf einmal hochladen, die Tags erst danach vergeben
 * (19.10.2026): Eine Datei darf vorübergehend ohne Tag sein. Die Liste unter
 * Planung › Dateien zeigt sie oben als „Ohne Tag“.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_files', function (Blueprint $table) {
            $table->foreignId('tag_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_files', function (Blueprint $table) {
            $table->foreignId('tag_id')->nullable(false)->change();
        });
    }
};
