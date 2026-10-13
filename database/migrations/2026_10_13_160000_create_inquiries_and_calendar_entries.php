<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anfragen (Optionen) wie vc_inquiries der PHP-Version: ein Veranstalter
        // fragt einen oder mehrere Termine an, bestätigt wird einer davon als Event
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promoter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('option_deadline')->nullable();
            $table->text('comment')->nullable();
            $table->string('event_type1', 120)->nullable();
            $table->string('event_type2', 120)->nullable();
            $table->json('seating')->nullable();
            $table->json('areas')->nullable();
            $table->string('status', 20)->default('offen'); // offen | bestätigt | zurückgezogen
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 191)->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('inquiry_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();
            $table->date('option_date');
            $table->timestamps();
            $table->unique(['inquiry_id', 'option_date']);
            $table->index('option_date');
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->foreignId('confirmed_date_id')->nullable()->after('event_id')->constrained('inquiry_dates')->nullOnDelete();
        });

        // Eigene Einträge auf Kalender-Ebenen wie Wartung oder Technik (vc_calendar_entries)
        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->id();
            $table->string('calendar_key', 32);
            $table->foreign('calendar_key')->references('key')->on('calendars')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 191)->nullable();
            $table->timestamps();
            $table->index(['calendar_key', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_entries');
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_date_id');
        });
        Schema::dropIfExists('inquiry_dates');
        Schema::dropIfExists('inquiries');
    }
};
