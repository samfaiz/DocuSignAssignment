<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function envelope(): Envelope
    {
        $user = User::factory()->create();

        $document = Document::create([
            'owner_id' => $user->id,
            'filename' => 'contract.pdf',
            'storage_key' => 'documents/test/' . uniqid() . '.pdf',
            'sha256_original' => str_repeat('a', 64),
            'page_count' => 1,
            'size_bytes' => 1024,
        ]);

        return Envelope::create([
            'document_id' => $document->id,
            'sender_id' => $user->id,
            'subject' => 'Test agreement',
            'status' => Envelope::STATUS_DRAFT,
        ]);
    }

    public function test_chain_verifies_across_many_events(): void
    {
        $envelope = $this->envelope();
        $logger = app(AuditLogger::class);

        for ($i = 0; $i < 10; $i++) {
            $logger->record($envelope, 'test.event', ['index' => $i]);
        }

        $result = $logger->verifyChain($envelope);

        $this->assertTrue($result['valid'], $result['reason'] ?? '');
        $this->assertSame(10, $result['count']);
    }

    public function test_each_event_links_to_the_previous_hash(): void
    {
        $envelope = $this->envelope();
        $logger = app(AuditLogger::class);

        $first = $logger->record($envelope, 'envelope.created');
        $second = $logger->record($envelope, 'envelope.sent');

        $this->assertSame(AuditEvent::GENESIS_HASH, $first->prev_hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertSame(1, $first->seq);
        $this->assertSame(2, $second->seq);
    }

    /**
     * The bug this guards against: Postgres jsonb stores a parsed structure and
     * returns object keys in its own order, so a payload written in one order
     * read back in another and the recomputed hash stopped matching.
     */
    public function test_hash_survives_a_jsonb_round_trip_regardless_of_key_order(): void
    {
        $envelope = $this->envelope();

        $event = app(AuditLogger::class)->record($envelope, 'recipient.email_sent', [
            'zulu' => 'last',
            'alpha' => 'first',
            'nested' => ['yankee' => 2, 'bravo' => 1],
            'list' => [3, 1, 2],
        ]);

        $storedHash = $event->hash;

        // Fresh instance straight from the database, not the in-memory model.
        $reloaded = AuditEvent::findOrFail($event->id);

        $this->assertSame($storedHash, $reloaded->computeHash());
        // Lists keep their order — only object keys are sorted.
        $this->assertSame([3, 1, 2], $reloaded->payload['list']);
    }

    public function test_tampering_with_a_payload_breaks_the_chain(): void
    {
        $envelope = $this->envelope();
        $logger = app(AuditLogger::class);

        $logger->record($envelope, 'envelope.created');
        $target = $logger->record($envelope, 'recipient.signed', ['recipient' => 'real@example.test']);
        $logger->record($envelope, 'envelope.completed');

        // Bypass both the model guard and the trigger, the way an attacker with
        // direct database access would have to.
        DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update');
        DB::table('audit_events')
            ->where('id', $target->id)
            ->update(['payload' => json_encode(['recipient' => 'attacker@example.test'])]);
        DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update');

        $result = $logger->verifyChain($envelope->fresh());

        $this->assertFalse($result['valid']);
        $this->assertSame(2, $result['broken_at']);
        $this->assertStringContainsString('recomputed', $result['reason']);
    }

    public function test_database_rejects_updates_to_audit_events(): void
    {
        $envelope = $this->envelope();
        $event = app(AuditLogger::class)->record($envelope, 'envelope.created');

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('audit_events')->where('id', $event->id)->update(['type' => 'tampered']);
    }

    public function test_database_rejects_deletes_from_audit_events(): void
    {
        $envelope = $this->envelope();
        $event = app(AuditLogger::class)->record($envelope, 'envelope.created');

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('audit_events')->where('id', $event->id)->delete();
    }

    public function test_model_refuses_to_update_an_audit_event(): void
    {
        $envelope = $this->envelope();
        $event = app(AuditLogger::class)->record($envelope, 'envelope.created');

        $this->expectException(RuntimeException::class);

        $event->update(['type' => 'tampered']);
    }
}
