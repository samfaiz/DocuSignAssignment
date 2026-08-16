<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();

            $table->string('role', 16)->default('signer');
            $table->unsignedSmallInteger('routing_order')->default(1);
            $table->string('status', 24)->default('pending')->index();

            // Only the SHA-256 of the access token is stored. A database dump
            // therefore does not hand an attacker working signing links, in the
            // same way a users table stores password hashes rather than
            // passwords. Indexed because it is the lookup key.
            $table->char('access_token_hash', 64)->unique();
            $table->timestamp('token_expires_at')->nullable();

            // Second factor. Possession of the emailed link proves someone read
            // that inbox; the OTP is what ties the ceremony to the intended
            // person rather than to anyone the link was forwarded to.
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('otp_locked_until')->nullable();
            $table->boolean('otp_verified')->default(false);

            // How this signer was actually identified, recorded for the
            // certificate of completion. "Verified" without a method is not
            // evidence of anything.
            $table->string('auth_method')->nullable();

            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();

            $table->string('last_ip', 45)->nullable();
            $table->text('last_user_agent')->nullable();

            $table->timestamps();

            $table->index(['envelope_id', 'routing_order']);
            $table->unique(['envelope_id', 'email']);
        });

        DB::statement("
            ALTER TABLE recipients ADD CONSTRAINT recipients_status_check
            CHECK (status IN ('pending','sent','viewed','signed','declined','expired'))
        ");

        DB::statement("
            ALTER TABLE recipients ADD CONSTRAINT recipients_role_check
            CHECK (role IN ('signer','viewer','approver'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('recipients');
    }
};
