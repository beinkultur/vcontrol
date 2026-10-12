<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standard-Saalpläne der Halle (PDF) mit den Bereichen, die der Plan nutzt
        Schema::create('seating_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('areas')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Je Event entweder ein Standard-Saalplan oder ein eigener (PDF)
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('seating_plan_id')->nullable()->after('seating')->constrained('seating_plans')->nullOnDelete();
            $table->string('seating_plan_path')->nullable()->after('seating_plan_id');
            $table->string('seating_plan_original_name')->nullable()->after('seating_plan_path');
            $table->string('seating_plan_mime_type')->nullable()->after('seating_plan_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seating_plan_id');
            $table->dropColumn(['seating_plan_path', 'seating_plan_original_name', 'seating_plan_mime_type']);
        });
        Schema::dropIfExists('seating_plans');
    }
};
