<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The evidence log.
     *
     * Two properties make this more than a table of rows someone could edit:
     *
     *  1. Each row's hash covers the previous row's hash, so the log is a
     *     chain. Editing, deleting or reordering any event invalidates every
     *     hash after it, and the break is detectable by recomputation.
     *  2. UPDATE and DELETE are rejected by the database itself, so even a
     *     compromised application account — or a careless raw query — cannot
     *     quietly rewrite history.
     *
     * The chain detects tampering; it does not prevent it. An attacker with
     * write access could rebuild the whole chain from the edited point onward.
     * That is why the final hash also ends up inside a PAdES-signed PDF that
     * is timestamped by an authority we do not control.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // Deliberately restrictOnDelete, not cascade: audit history outlives
            // the envelope it describes. Envelopes get voided, never deleted.
            $table->foreignId('envelope_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_id')->nullable()
                ->constrained()->nullOnDelete();

            // Per-envelope sequence number. Gaps or duplicates are themselves
            // evidence of interference, so it is unique and dense.
            $table->unsignedInteger('seq');

            $table->string('type', 48);
            $table->string('actor')->nullable();

            $table->jsonb('payload')->default('{}');

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('occurred_at', 6);

            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->timestamp('created_at')->nullable();

            $table->unique(['envelope_id', 'seq']);
            $table->index(['envelope_id', 'occurred_at']);
            $table->index('type');
        });

        // GIN index so payloads stay queryable ("show me every event from this
        // IP") without a second denormalised table.
        DB::statement('CREATE INDEX audit_events_payload_gin ON audit_events USING GIN (payload)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_reject_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_events is append-only; % on id=% was rejected',
                    TG_OP, OLD.id
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER audit_events_no_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_reject_mutation();

            CREATE TRIGGER audit_events_no_delete
                BEFORE DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('
            DROP TRIGGER IF EXISTS audit_events_no_update ON audit_events;
            DROP TRIGGER IF EXISTS audit_events_no_delete ON audit_events;
            DROP FUNCTION IF EXISTS audit_events_reject_mutation();
        ');

        Schema::dropIfExists('audit_events');
    }
};
