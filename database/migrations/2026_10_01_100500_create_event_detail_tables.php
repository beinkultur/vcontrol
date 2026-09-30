<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1:1-Zusatzdaten der Events, je Bereich eine Tabelle (siehe docs/EVENTS.md).
 * Schlüssel ist jeweils event_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_finances', function (Blueprint $table) {
            $this->eventKey($table);
            $table->string('contract_status', 120)->nullable();
            $table->json('accounting_status')->nullable(); // FIBU-Status, Mehrfachauswahl
            $table->string('price_list', 120)->nullable();
            $table->decimal('rent', 12, 2)->nullable();
            $table->json('invoice_numbers')->nullable(); // in der PHP-Version drei Spalten
            $table->boolean('accounting_closed')->default(false); // Abrechnung abgeschlossen
        });

        Schema::create('event_schedules', function (Blueprint $table) {
            $this->eventKey($table);
            foreach (['get_in', 'load_in', 'admission', 'vip_admission', 'start_time', 'end_time', 'curfew', 'load_out'] as $column) {
                $table->time($column)->nullable();
            }
        });

        Schema::create('event_pr', function (Blueprint $table) {
            $this->eventKey($table);
            $table->date('pr_date')->nullable();
            $table->string('pr_status', 120)->nullable();
        });

        Schema::create('event_operations', function (Blueprint $table) {
            $this->eventKey($table);
            // Verweise auf Zählerstände (Schlüssel aus AppSheet), bis das Zähler-Modul steht
            $table->string('power_start_ref', 36)->nullable();
            $table->string('power_end_ref', 36)->nullable();
            $table->integer('power_consumption')->nullable();
            $table->text('backstages')->nullable();
            $table->text('offices')->nullable();
            $table->boolean('bus_power')->nullable();
            $table->boolean('house_delay')->nullable();
        });

        Schema::create('event_stages', function (Blueprint $table) {
            $this->eventKey($table);
            $table->text('stage_info')->nullable();
            foreach (['width', 'depth', 'height', 'wing_sl_width', 'wing_sl_depth', 'wing_sr_width', 'wing_sr_depth'] as $column) {
                $table->decimal($column, 8, 2)->nullable(); // Meter
            }
            $table->integer('wing_sl_offset')->default(0);
            $table->integer('wing_sr_offset')->default(0);
            $table->integer('extra_platforms')->nullable();
            $table->integer('rollpodest_width')->nullable();
            $table->integer('rollpodest_depth')->nullable();
            $table->integer('podest_total')->nullable();
            $table->string('stair_third', 32)->nullable();
            $table->integer('stair_sl_offset')->default(0);
            $table->integer('stair_sr_offset')->default(0);
            $table->integer('backwall_cm')->default(0);
            $table->text('other_info')->nullable();
            $table->text('stage_notes')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('sold_out_award')->nullable();
        });

        Schema::create('event_checklists', function (Blueprint $table) {
            $this->eventKey($table);
            // ja / nein / entfällt
            foreach (['hands', 'traffic', 'pvc_setup', 'pvc_teardown', 'cleaning', 'interim_cleaning', 'bar_setup', 'bar_teardown', 'chairs_ordered', 'merch_fee_check', 'special_cleaning'] as $column) {
                $table->string($column, 3)->nullable();
            }
            $table->string('merch_fee', 120)->nullable();
            $table->boolean('power_ant')->nullable();
            $table->boolean('house_rig_early')->nullable();
            $table->boolean('briefing_complete')->default(false);
        });
    }

    private function eventKey(Blueprint $table): void
    {
        $table->foreignId('event_id')->primary()->constrained()->cascadeOnDelete();
    }

    public function down(): void
    {
        foreach (['event_checklists', 'event_stages', 'event_operations', 'event_pr', 'event_schedules', 'event_finances'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
