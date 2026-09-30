<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promoters', function (Blueprint $table) {
            $table->id();
            $table->string('short_name', 120)->nullable();
            $table->string('name');
            // Kundennummer, 5-stellig – Präfix der VA-ID
            $table->string('customer_no', 50)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->boolean('is_archived')->default(false)->index();
            $table->timestamps();
        });

        // In der PHP-Version vier feste Spaltengruppen am Veranstalter, hier beliebig viele
        Schema::create('promoter_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promoter_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('role', 120)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promoter_contacts');
        Schema::dropIfExists('promoters');
    }
};
