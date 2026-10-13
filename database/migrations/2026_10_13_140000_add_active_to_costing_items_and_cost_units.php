<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costing-Posten lassen sich ausblenden (is_active); Einheiten der Preislisten
 * sind Feldoptionen („Einheit (Preislisten)“), die der Admin pflegt – vorbelegt
 * mit den bisher genutzten.
 */
return new class extends Migration
{
    private const UNITS = ['pauschal', 'je Stück', 'jeweils', 'pro verkauftem Ticket', 'pro Person/Stunde', 'pro Raum/Tag', 'je Anschluss', '%'];

    public function up(): void
    {
        Schema::table('costing_items', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('fixed_price');
        });

        $used = DB::table('price_list_items')->whereNotNull('unit')->distinct()->pluck('unit')
            ->merge(DB::table('costing_items')->whereNotNull('unit')->distinct()->pluck('unit'))
            ->map(fn (string $unit): string => trim($unit));
        $units = collect(self::UNITS)->merge($used)->filter()->unique()->values();
        $existing = DB::table('field_options')->where('field_key', 'cost_unit')->pluck('value')->all();
        foreach ($units as $index => $unit) {
            if (!in_array($unit, $existing, true)) {
                DB::table('field_options')->insert([
                    'field_key' => 'cost_unit',
                    'value' => $unit,
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('field_options')->where('field_key', 'cost_unit')->delete();
        Schema::table('costing_items', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
