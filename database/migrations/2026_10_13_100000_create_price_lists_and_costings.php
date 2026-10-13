<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preislisten der Halle mit ihren Kostenpunkten (Modul Preislisten & Costing)
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->date('valid_from')->nullable();
            $table->text('intro')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->string('section', 120)->nullable();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('unit', 60)->nullable();
            $table->decimal('price', 10, 2)->nullable();          // leer: nach Aufwand
            $table->decimal('minimum', 10, 2)->nullable();        // Mindestbetrag
            $table->decimal('default_factor', 10, 2)->nullable(); // Vorgabe im Costing
            $table->boolean('factor_from_pax')->default(false);   // Faktor = PAX erwartet
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('price_list_id')->nullable()->after('seating_plan_mime_type')->constrained('price_lists')->nullOnDelete();
        });

        // Costing je Event, in Fassungen: verschickt bleibt eine Fassung, wie sie ist
        Schema::create('costings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version');
            $table->text('note')->nullable();
            $table->decimal('vat_rate', 5, 2)->default(19);
            $table->dateTime('sent_at')->nullable();
            $table->json('sent_to')->nullable();
            $table->string('sent_by_name', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 191)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'version']);
        });

        Schema::create('costing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('costing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section', 120)->nullable();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('unit', 60)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('minimum', 10, 2)->nullable();
            $table->decimal('factor', 10, 2)->default(0);
            $table->boolean('fixed_price')->default(false); // Preis aus der Preisliste, im Costing nicht änderbar
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('costing_items');
        Schema::dropIfExists('costings');
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
        });
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
