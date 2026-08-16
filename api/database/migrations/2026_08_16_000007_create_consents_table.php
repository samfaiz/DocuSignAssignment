<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consent to transact electronically.
     *
     * The US ESIGN Act requires that a consumer affirmatively consent to doing
     * business electronically before an electronic signature binds them. What
     * matters evidentially is not a boolean but *which disclosure text* was on
     * screen when they agreed — so the version is recorded, and disclosure
     * texts are immutable once published.
     */
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();

            $table->string('disclosure_version', 64);
            $table->char('disclosure_sha256', 64);

            $table->timestamp('accepted_at');
            $table->string('ip', 45);
            $table->text('user_agent')->nullable();

            $table->timestamps();
            $table->unique(['recipient_id', 'disclosure_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
