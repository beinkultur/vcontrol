<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tageszugangscodes wie vc_access_codes der PHP-Version: ein Code je Tag, gültig
 * ab valid_from (in der Regel 6 Uhr) bis zum valid_from des Folgetags. Das ▲ vor
 * dem Code gehört zur Eingabe am Zugangssystem und wird nicht gespeichert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->dateTime('valid_from');
            $table->date('valid_on')->unique(); // Kalendertag von valid_from
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 191)->nullable();
            $table->timestamps();
            $table->index('valid_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_codes');
    }
};
