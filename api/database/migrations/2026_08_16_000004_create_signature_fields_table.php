<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();

            // Every field belongs to exactly one recipient. This is what makes
            // "recipient A cannot fill recipient B's field" checkable.
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();

            $table->string('type', 24);
            $table->unsignedSmallInteger('page');

            // Normalised 0..1 with a top-left origin, matching what the browser
            // measures against a rendered pdf.js canvas. Storing fractions
            // rather than points means the placement survives zoom, DPI and
            // page-size differences, and the server converts to PDF user space
            // at stamping time so the client never dictates where ink lands.
            $table->decimal('x', 8, 6);
            $table->decimal('y', 8, 6);
            $table->decimal('w', 8, 6);
            $table->decimal('h', 8, 6);

            $table->boolean('required')->default(true);
            $table->timestamps();

            $table->index(['envelope_id', 'page']);
            $table->index('recipient_id');
        });

        DB::statement("
            ALTER TABLE signature_fields ADD CONSTRAINT signature_fields_type_check
            CHECK (type IN ('signature','initial','date','text','checkbox'))
        ");

        // Coordinates must describe a box that actually sits on the page.
        DB::statement('
            ALTER TABLE signature_fields ADD CONSTRAINT signature_fields_bounds_check
            CHECK (x >= 0 AND y >= 0 AND w > 0 AND h > 0 AND x + w <= 1 AND y + h <= 1)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_fields');
    }
};
