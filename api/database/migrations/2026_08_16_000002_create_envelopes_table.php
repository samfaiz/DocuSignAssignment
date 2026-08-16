<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelopes', function (Blueprint $table) {
            $table->id();

            // Public identifier used in signer URLs. Sequential integers would
            // let anyone enumerate other people's envelopes.
            $table->uuid('uuid')->unique();

            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

            $table->string('subject');
            $table->text('message')->nullable();

            $table->string('status', 24)->default('draft')->index();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
        });

        // Valid states, enforced by the database rather than only by the model,
        // so a stray raw update cannot park an envelope in a state the
        // application does not know how to handle.
        DB::statement("
            ALTER TABLE envelopes ADD CONSTRAINT envelopes_status_check
            CHECK (status IN ('draft','sent','in_progress','completed','declined','voided','expired'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('envelopes');
    }
};
