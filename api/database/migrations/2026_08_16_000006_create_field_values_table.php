<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_values', function (Blueprint $table) {
            $table->id();

            // One value per field: filling a field twice updates, never appends.
            $table->foreignId('signature_field_id')->unique()->constrained()->cascadeOnDelete();

            $table->text('text_value')->nullable();
            $table->foreignId('signature_asset_id')->nullable()
                ->constrained('signature_assets')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_values');
    }
};
