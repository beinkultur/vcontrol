<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Listen am Event: Leistungen, Rollen, Raumbelegung, Eingangsrechnungen. */
return new class extends Migration
{
    public function up(): void
    {
        // Nur Leistungen mit Inhalt – eine fehlende Zeile heißt „nicht festgelegt“
        Schema::create('event_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('service', 40); // App\Enums\ServiceCode
            $table->foreignId('trade_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_label')->nullable(); // Anbieter ohne eigenes Gewerk
            $table->string('responsible', 20)->nullable(); // arena | promoter
            $table->boolean('is_active')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'service']);
        });

        // Auf-/Abbau-Paare (Bühne, Hausrigg …): aktiv und ob getrennt geplant
        Schema::create('event_service_groups', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('group_key', 40);
            $table->boolean('is_active')->default(false);
            $table->boolean('split_setup_teardown')->default(false);
            $table->primary(['event_id', 'group_key']);
        });

        // Rolle am Event (Projektleitung, House Rep. …) an Mitarbeiter, Gewerk oder Benutzer
        Schema::create('event_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32); // App\Enums\AssignmentRole
            $table->string('assignee_type', 20); // employee | trade | user
            $table->unsignedBigInteger('assignee_id');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'role']);
            $table->index(['assignee_type', 'assignee_id']);
        });

        // Räume in Nutzung lassen sich nicht löschen
        Schema::create('event_room', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('usage_type', 20); // backstage | office
            $table->primary(['event_id', 'room_id', 'usage_type']);
        });

        // Erwartete Eingangsrechnungen der Dienstleister (sechs feste Positionen)
        Schema::create('event_incoming_invoices', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_key', 40);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_received')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['event_id', 'invoice_key']);
        });
    }

    public function down(): void
    {
        foreach (['event_incoming_invoices', 'event_room', 'event_assignments', 'event_service_groups', 'event_services'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
