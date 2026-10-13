<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Die Preisliste war ein Auswahlfeld der Buchhaltung (event_finances.price_list,
 * Werte aus der Feldoption „Preisliste“). Jetzt ist sie ein eigener Datensatz am
 * Event: Jeder bisherige Wert wird eine Preisliste ohne Kostenpunkte, die Events
 * behalten ihre Zuordnung.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $options = DB::table('field_options')->where('field_key', 'price_list')->orderBy('sort_order')->orderBy('id')->get(['value', 'is_active']);
        $used = DB::table('event_finances')->whereNotNull('price_list')->distinct()->pluck('price_list');

        $ids = [];
        foreach ($options->pluck('value')->merge($used) as $value) {
            $name = mb_substr(trim((string) $value), 0, 120);
            if ($name === '' || isset($ids[$name])) {
                continue;
            }
            $option = $options->first(fn (object $option): bool => trim((string) $option->value) === $name);
            $ids[$name] = DB::table('price_lists')->insertGetId([
                'name' => $name,
                'is_active' => $option !== null && (bool) $option->is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($used as $value) {
            $name = mb_substr(trim((string) $value), 0, 120);
            if (isset($ids[$name])) {
                DB::table('events')
                    ->whereIn('id', DB::table('event_finances')->where('price_list', $value)->select('event_id'))
                    ->update(['price_list_id' => $ids[$name]]);
            }
        }

        Schema::table('event_finances', function (Blueprint $table) {
            $table->dropColumn('price_list');
        });
        DB::table('field_options')->where('field_key', 'price_list')->delete();
    }

    public function down(): void
    {
        Schema::table('event_finances', function (Blueprint $table) {
            $table->string('price_list', 120)->nullable()->after('accounting_status');
        });

        $lists = DB::table('price_lists')->orderBy('name')->get(['id', 'name', 'is_active']);
        foreach ($lists as $index => $list) {
            DB::table('field_options')->insert([
                'field_key' => 'price_list',
                'value' => $list->name,
                'is_active' => $list->is_active,
                'sort_order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('event_finances')
                ->whereIn('event_id', DB::table('events')->where('price_list_id', $list->id)->select('id'))
                ->update(['price_list' => $list->name]);
        }
    }
};
