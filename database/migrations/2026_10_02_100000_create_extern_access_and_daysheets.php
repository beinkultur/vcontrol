<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Externer Zugriff (Freelancer, Gewerke) und Daysheets:
 * - Dateien und Notizen sehen beteiligte Externe, bis jemand „Für Externe
 *   verbergen“ anhakt (Verträge kommen in der Regel nicht an die Events).
 * - Daysheets: versendete Links auf die Event-Infos, gültig bis zum Tag nach der
 *   Veranstaltung. Gespeichert wird nur der Hash des Schlüssels, nicht der Link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_files', function (Blueprint $table) {
            $table->boolean('hidden_from_externals')->default(false)->after('is_shared');
        });

        Schema::table('event_notes', function (Blueprint $table) {
            $table->boolean('hidden_from_externals')->default(false)->after('body');
        });

        Schema::create('daysheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256 des Schlüssels im Link
            $table->json('recipients_to');
            $table->json('recipients_bcc')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 191)->nullable();
            $table->timestamps();
            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daysheets');

        Schema::table('event_notes', function (Blueprint $table) {
            $table->dropColumn('hidden_from_externals');
        });

        Schema::table('event_files', function (Blueprint $table) {
            $table->dropColumn('hidden_from_externals');
        });
    }
};
