<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durchführung › Checklisten und Schäden.
 *
 * Checklisten: die „EventsCheckliste“ aus AppSheet (13 Prüfpunkte mit Anmerkung,
 * House-Rep. und Prom.-Rep. mit Unterschrift). Die PHP-Version hat dafür nur
 * einen Platzhalter, es gibt keine Altdaten.
 *
 * Schäden wie in der PHP-Version (vc_damages, vc_damage_photos): auch ohne
 * Event („allgemein“), mit Fotos (hier als Liste von Pfaden), „behoben“.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_show_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->dateTime('checked_at');
            $table->string('house_rep', 120)->nullable();
            $table->mediumText('house_rep_signature')->nullable();
            $table->string('promoter_rep', 120)->nullable();
            $table->mediumText('promoter_rep_signature')->nullable();
            $table->json('checks')->nullable(); // Prüfpunkt => {value: yes|no, note}
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->text('description');
            $table->boolean('is_fixed')->default(false);
            $table->json('photos')->nullable(); // Pfade auf der Disk „local“
            $table->json('photo_names')->nullable(); // Pfad => ursprünglicher Dateiname
            $table->string('recorded_by_name', 200)->nullable(); // aus dem AppSheet-Import
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
            $table->index(['event_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damages');
        Schema::dropIfExists('event_show_checklists');
    }
};
