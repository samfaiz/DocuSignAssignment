<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('filename');
            // Object storage key. The PDF itself never goes in the database:
            // blobs bloat backups, slow replication and cannot be streamed.
            $table->string('storage_key')->unique();

            // Hash of the bytes exactly as uploaded. This is the anchor of the
            // integrity story — everything downstream is compared against it.
            $table->char('sha256_original', 64)->index();

            $table->unsignedInteger('page_count');
            $table->unsignedBigInteger('size_bytes');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
