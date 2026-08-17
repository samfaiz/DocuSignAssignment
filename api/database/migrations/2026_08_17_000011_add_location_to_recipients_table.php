<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the signer said they were.
     *
     * Deliberately separate from the IP address already on this table. An IP is
     * observed by the server and cannot be set by the client; coordinates come
     * from the browser's Geolocation API, are supplied by the client, and can be
     * spoofed by anyone willing to open developer tools. They are therefore
     * recorded as *signer-reported*, and the certificate of completion says so.
     *
     * The decision is stored whichever way it goes. A signer who declined is a
     * fact worth having: it distinguishes "chose not to share" from "was never
     * asked", and only the first tells you anything about the ceremony.
     */
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('location_consent', 16)->default('not_asked');

            // Seven decimal places is roughly a centimetre — far finer than any
            // browser reports, but it costs nothing and avoids silently
            // truncating whatever the device gives us.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // The browser's own error estimate, in metres. Without it a
            // coordinate is unfalsifiable: 50 metres and 50 kilometres look
            // identical once written down.
            $table->unsignedInteger('location_accuracy_m')->nullable();

            $table->timestamp('location_captured_at')->nullable();
        });

        DB::statement("
            ALTER TABLE recipients ADD CONSTRAINT recipients_location_consent_check
            CHECK (location_consent IN ('not_asked','granted','denied','unsupported','failed'))
        ");

        DB::statement('
            ALTER TABLE recipients ADD CONSTRAINT recipients_location_bounds_check
            CHECK (
                (latitude IS NULL AND longitude IS NULL)
                OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)
            )
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recipients DROP CONSTRAINT IF EXISTS recipients_location_consent_check');
        DB::statement('ALTER TABLE recipients DROP CONSTRAINT IF EXISTS recipients_location_bounds_check');

        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn([
                'location_consent', 'latitude', 'longitude',
                'location_accuracy_m', 'location_captured_at',
            ]);
        });
    }
};
