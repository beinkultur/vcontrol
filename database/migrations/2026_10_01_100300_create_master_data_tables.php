<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gewerke: Dienstleister der Halle (Reinigung, Technik, Sicherheit …)
        $this->createIfMissing('trades', function (Blueprint $table) {
            $table->id();
            $table->string('short_name', 120)->nullable();
            $table->string('name');
            // Leistungsbereiche, z. B. ["Umbau", "Verkehr"] – in der PHP-Version Komma-Text
            $table->json('categories')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('city', 120)->nullable();
            $table->boolean('is_archived')->default(false)->index();
            $table->timestamps();
        });

        $this->createIfMissing('employees', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120);
            $table->string('initials', 20)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            // Positionen aus der Feldoption employee_position, z. B. ["VL", "VfV"]
            $table->json('positions')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Auswahlwerte der Event-Felder (VA-Status, Bestuhlung, FIBU-Status …)
        $this->createIfMissing('field_options', function (Blueprint $table) {
            $table->id();
            $table->string('field_key', 40)->index();
            $table->string('value');
            // Nur VA-Kategorie 2: gehört zu diesem Wert der VA-Kategorie 1
            $table->string('parent_value', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createIfMissing('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createIfMissing('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('inventory_categories')->restrictOnDelete();
            $table->string('name', 200);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Einstellungen dieser Halle, z. B. ihr Name
        $this->createIfMissing('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Verweise auf Datensätze, die es (noch) nicht gibt, würden den Fremdschlüssel
        // blockieren – etwa wenn Benutzer schon importiert sind, die Stammdaten aber nicht.
        foreach (['employee_id' => 'employees', 'trade_id' => 'trades'] as $column => $target) {
            DB::table('users')->whereNotNull($column)
                ->whereNotIn($column, DB::table($target)->select('id'))
                ->update([$column => null]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('trade_id')->references('id')->on('trades')->nullOnDelete();
        });
    }

    /** Anlegen nur, wenn die Tabelle fehlt – die Migration bleibt nach einem Abbruch wiederholbar. */
    private function createIfMissing(string $table, Closure $definition): void
    {
        if (!Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['trade_id']);
        });
        Schema::dropIfExists('settings');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_categories');
        Schema::dropIfExists('field_options');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('trades');
    }
};
