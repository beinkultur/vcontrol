<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zählerstände (15.10.2026): Welche Zähler es gibt (Strom, Wasser, Fernwärme …)
 * mit Einheit legt die Venue fest, ebenso ob ein Zähler je Event abgelesen
 * wird (Stand Anfang/Ende in Durchführung › Betrieb). Stände gibt es je Event
 * oder als freie Ablesung (Seite Zählerstände); „Zählerwechsel“ (restart)
 * heißt: der Stand beginnt neu, seit dem vorigen zählt kein Verbrauch.
 *
 * Der Stromzähler des Events (event_operations.power_meter_*) wird der Zähler
 * „Strom“ (kWh, je Event), abgelesen am Morgen des ersten und am Abend des
 * letzten Event-Tags (wie App\Support\MeterReadings::eventTime). Der daraus
 * berechnete Verbrauch (power_consumption) entfällt, er ergibt sich aus den
 * Ständen. Auf ipa stand nichts darin; die AppSheet-Verweise power_*_ref
 * bleiben für den AppSheet-Import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('unit', 20);
            $table->string('number', 60)->nullable();
            $table->string('location', 120)->nullable();
            // Je Event Stand Anfang/Ende erfassen (wie bisher der Strom)
            $table->boolean('per_event')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            // Je Event: Anfang oder Ende; sonst eine freie Ablesung
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('position', 8)->nullable();
            $table->dateTime('read_at');
            $table->decimal('value', 14, 3);
            $table->boolean('restart')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 191)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 191)->nullable();
            $table->timestamps();
            $table->index(['meter_id', 'read_at']);
            $table->unique(['meter_id', 'event_id', 'position']);
        });

        $now = now();
        $meterId = DB::table('meters')->insertGetId([
            'name' => 'Strom', 'unit' => 'kWh', 'per_event' => true, 'is_active' => true, 'sort_order' => 10,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $operations = DB::table('event_operations')
            ->join('events', 'events.id', '=', 'event_operations.event_id')
            ->where(fn ($query) => $query->whereNotNull('event_operations.power_meter_start')->orWhereNotNull('event_operations.power_meter_end'))
            ->get(['event_operations.event_id', 'event_operations.power_meter_start', 'event_operations.power_meter_end', 'events.starts_at', 'events.ends_at']);
        foreach ($operations as $operation) {
            $first = substr((string) ($operation->starts_at ?? $now->toDateTimeString()), 0, 10);
            $last = max($first, substr((string) ($operation->ends_at ?? $first), 0, 10));
            foreach (['start' => [$operation->power_meter_start, $first . ' 00:00:00'], 'end' => [$operation->power_meter_end, $last . ' 23:59:59']] as $position => [$value, $readAt]) {
                if ($value !== null) {
                    DB::table('meter_readings')->insert([
                        'meter_id' => $meterId, 'event_id' => $operation->event_id, 'position' => $position,
                        'read_at' => $readAt, 'value' => $value, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        Schema::table('event_operations', function (Blueprint $table) {
            $table->dropColumn(['power_meter_start', 'power_meter_end', 'power_consumption']);
        });
    }

    public function down(): void
    {
        Schema::table('event_operations', function (Blueprint $table) {
            $table->decimal('power_meter_start', 12, 2)->nullable()->after('power_end_ref');
            $table->decimal('power_meter_end', 12, 2)->nullable()->after('power_meter_start');
            $table->integer('power_consumption')->nullable()->after('power_meter_end');
        });

        // Zurück nur der Strom je Event
        $meterId = DB::table('meters')->where('name', 'Strom')->where('per_event', true)->orderBy('id')->value('id');
        if ($meterId !== null) {
            foreach (DB::table('meter_readings')->where('meter_id', $meterId)->whereNotNull('event_id')->get() as $reading) {
                DB::table('event_operations')->updateOrInsert(['event_id' => $reading->event_id], [
                    $reading->position === 'end' ? 'power_meter_end' : 'power_meter_start' => $reading->value,
                ]);
            }
        }

        Schema::drop('meter_readings');
        Schema::drop('meters');
    }
};
