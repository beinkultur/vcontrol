<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advancing (Modul, 17.11.2026): Fragenkataloge als Struktur aus Abschnitten,
 * Infotexten und Fragen (JSON) und je Event eine Kopie davon mit Vorbelegung des
 * Planers und den Antworten des Veranstalters. Der Link enthält einen Schlüssel:
 * gesucht wird über den Hash, der Schlüssel selbst liegt verschlüsselt (der
 * Planer kann den Link später kopieren). Später bekommt der Katalog eine Venue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advancing_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('intro')->nullable();
            $table->json('structure');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('event_advancings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('advancing_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_name', 120);
            $table->text('intro')->nullable();
            $table->json('structure');
            $table->json('prefill')->nullable();
            $table->json('answers')->nullable();
            $table->char('token_hash', 64)->nullable()->unique(); // sha256 des Schlüssels im Link
            $table->text('token')->nullable(); // verschlüsselt (Cast encrypted)
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->json('recipients_to')->nullable();
            $table->json('recipients_bcc')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('first_opened_at')->nullable();
            $table->dateTime('last_saved_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedInteger('submit_count')->default(0);
            $table->dateTime('closed_at')->nullable();
            $table->string('closed_by_name', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_advancings');
        Schema::dropIfExists('advancing_templates');
    }
};
