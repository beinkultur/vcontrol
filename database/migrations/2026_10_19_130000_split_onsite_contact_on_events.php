<?php

use App\Support\OnsiteContact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ansprechpartner vor Ort (Planung › Kontakte, 19.10.2026): Vorname, Name,
 * Telefon und E-Mail statt eines Textfelds. Der bisherige Text wird mit
 * OnsiteContact::parse() aufgeteilt – „Max Mustermann (0170-5555)“ wird zu
 * Max / Mustermann / 0170-5555. Ohne Änderungsprotokoll (direkt per Abfrage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('onsite_first_name', 100)->nullable()->after('onsite_contact');
            $table->string('onsite_last_name', 120)->nullable()->after('onsite_first_name');
            $table->string('onsite_phone', 50)->nullable()->after('onsite_last_name');
            $table->string('onsite_email', 255)->nullable()->after('onsite_phone');
        });

        DB::table('events')->whereNotNull('onsite_contact')->where('onsite_contact', '!=', '')->orderBy('id')
            ->chunkById(200, function ($events): void {
                foreach ($events as $event) {
                    DB::table('events')->where('id', $event->id)->update(OnsiteContact::parse($event->onsite_contact));
                }
            });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('onsite_contact');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('onsite_contact', 120)->nullable()->after('onsite_email');
        });

        DB::table('events')->orderBy('id')->chunkById(200, function ($events): void {
            foreach ($events as $event) {
                $text = OnsiteContact::text($event->onsite_first_name, $event->onsite_last_name, $event->onsite_phone, $event->onsite_email);
                if ($text !== null) {
                    DB::table('events')->where('id', $event->id)->update(['onsite_contact' => $text]);
                }
            }
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['onsite_first_name', 'onsite_last_name', 'onsite_phone', 'onsite_email']);
        });
    }
};
