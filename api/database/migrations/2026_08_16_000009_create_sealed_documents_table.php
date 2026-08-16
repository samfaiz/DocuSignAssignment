<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sealed_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('storage_key')->unique();

            // Hash of the document after signatures were composited but before
            // sealing, and of the final sealed bytes. Both appear on the
            // certificate of completion.
            $table->char('sha256_stamped', 64);
            $table->char('sha256_sealed', 64);

            // What was actually achieved, not what was requested. The sealing
            // service degrades if the timestamp authority is unreachable, and
            // recording the real level keeps the certificate honest.
            $table->string('pades_level', 24);
            $table->string('tsa_url')->nullable();

            $table->string('certificate_subject')->nullable();
            $table->string('certificate_serial', 64)->nullable();

            $table->unsignedInteger('page_count');
            $table->jsonb('warnings')->default('[]');

            $table->timestamp('sealed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sealed_documents');
    }
};
