<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();

            // Which of the three adoption modes produced this artefact. Worth
            // recording distinctly: a drawn mark, an uploaded image and a typed
            // name carry different evidential weight, and the certificate of
            // completion states which one was used.
            $table->string('kind', 16);

            $table->string('storage_key')->unique();

            // Hash of the exact PNG bytes that get composited into the PDF, so
            // the artefact in the document can be tied back to this record.
            $table->char('sha256', 64);

            // Only meaningful for typed signatures.
            $table->string('font_family')->nullable();

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->timestamps();
            $table->index('recipient_id');
        });

        DB::statement("
            ALTER TABLE signature_assets ADD CONSTRAINT signature_assets_kind_check
            CHECK (kind IN ('drawn','uploaded','typed'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_assets');
    }
};
