<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sicherungsläufe (App\Support\Backups): was wann in die Nextcloud ging oder scheiterte
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // datenbank | dateien
            $table->string('status', 20); // laeuft | ok | fehler
            $table->string('trigger', 20); // zeitplan | manuell | konsole
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
