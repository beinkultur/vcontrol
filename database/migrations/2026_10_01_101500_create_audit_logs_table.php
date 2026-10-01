<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Änderungsprotokoll wie vc_audit_logs der PHP-Version: wer hat wann was
 * angelegt, geändert oder gelöscht, mit den Werten vorher und nachher.
 * Geschrieben von App\Support\Audit, nie geändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable(); // bleibt lesbar, wenn das Konto gelöscht wird
            $table->string('subject', 64);                // Tabelle, z. B. events
            $table->string('subject_key', 100)->nullable();
            $table->string('subject_label', 200)->nullable(); // Bezeichnung zum Zeitpunkt der Änderung
            $table->unsignedBigInteger('event_id')->nullable()->index(); // auch nach dem Löschen noch zuordenbar
            $table->string('action', 20);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject', 'subject_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
