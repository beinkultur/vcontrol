<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kern der Events. Zusatzdaten (Finanzen, Zeiten, Bühne …) folgen als eigene
 * 1:1-Tabellen, siehe docs/EVENTS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            // Laufende Nummer (5-stellig) und VA-ID (Kundennummer + laufende Nummer)
            $table->string('va_nr', 20)->nullable()->index();
            $table->string('va_id', 50)->nullable()->index();
            $table->string('title');
            $table->foreignId('promoter_id')->nullable()->constrained()->nullOnDelete();
            // VA-Status und Kategorien: Werte aus den Feldoptionen
            $table->string('status', 60)->nullable()->index();
            $table->string('event_type1', 120)->nullable();
            $table->string('event_type2', 120)->nullable();
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('pax_expected')->nullable();
            $table->unsignedInteger('pax')->nullable(); // abgerechnet
            $table->json('areas')->nullable(); // Bereiche, Mehrfachauswahl
            $table->json('seating')->nullable(); // Bestuhlung, Mehrfachauswahl
            $table->string('ticketing', 120)->nullable();
            $table->string('wlan', 120)->nullable();
            $table->string('wlan_password', 120)->nullable();
            $table->text('description')->nullable();
            $table->text('booking_notes')->nullable(); // intern
            $table->string('onsite_contact', 120)->nullable();
            $table->boolean('doing_closed')->default(false); // Durchführung abgeschlossen
            $table->boolean('closed')->default(false)->index(); // Event abgeschlossen
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
