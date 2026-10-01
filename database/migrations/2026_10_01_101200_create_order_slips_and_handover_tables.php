<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durchführung wie in der PHP-Version: Bestellscheine (Artikel mit Preis,
 * Positionen mit Menge) und Übergabeprotokolle (Inventar an Empfänger, mit
 * Rückgabe). Positionen halten Name, Einheit und Preis zum Zeitpunkt der
 * Bestellung fest – spätere Änderungen am Artikel ändern alte Scheine nicht.
 * Unterschriften als PNG (data:-URL) wie dort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('article_categories')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('short_name', 120)->nullable();
            $table->string('unit', 80)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('order_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('ordered_from', 200); // „Bestellt von“
            $table->dateTime('ordered_at');
            $table->mediumText('signature')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_settled')->default(false); // abgerechnet
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable(); // „Bei wem“
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
            $table->index(['event_id', 'ordered_at']);
        });

        Schema::create('order_slip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_slip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained('article_categories')->restrictOnDelete();
            $table->string('category_name', 120);
            $table->string('article_name', 200);
            $table->string('unit', 80)->nullable();
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('line_total', 10, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::create('handover_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('handed_to', 200); // Empfänger
            $table->dateTime('handed_at');
            $table->mediumText('signature')->nullable(); // Unterschrift des Empfängers
            $table->string('status', 20)->default('open'); // open | returned
            $table->dateTime('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable(); // „übergeben von“
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
            $table->index(['event_id', 'handed_at']);
        });

        Schema::create('handover_protocol_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('handover_protocols')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('inventory_categories')->restrictOnDelete();
            $table->string('category_name', 120);
            $table->string('item_name', 200);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['handover_protocol_items', 'handover_protocols', 'order_slip_items', 'order_slips', 'articles', 'article_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
