<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dateien an Events wie in der PHP-Version (vc_event_file_tags, vc_event_files,
 * vc_event_file_links): je Datei ein Tag, eine Version und ein Stand; über-
 * greifende Dateien (is_shared) hängen über event_file_links an mehreren Events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_file_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('event_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('event_file_tags');
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('path', 500); // auf der Disk „local“
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamp('uploaded_at')->nullable();
            $table->boolean('is_shared')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('event_file_links', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('event_files')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->primary(['event_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_file_links');
        Schema::dropIfExists('event_files');
        Schema::dropIfExists('event_file_tags');
    }
};
