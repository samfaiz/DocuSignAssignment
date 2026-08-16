<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runtime mail configuration.
     *
     * A single row. Mail credentials normally live in .env, but this deployment
     * needs them changeable without shell access — so they live here instead and
     * are layered over the config at boot.
     *
     * The password column holds ciphertext, encrypted with the application key
     * via an Eloquent cast. A database dump therefore does not hand over the
     * ability to send mail as this domain, which matters more than usual here:
     * the mail account is what delivers signing links and one-time passcodes.
     */
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();

            $table->string('mailer', 32)->default('smtp');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('encryption', 16)->nullable();

            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_test_error')->nullable();

            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
