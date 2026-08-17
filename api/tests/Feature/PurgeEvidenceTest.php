<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Retention enforcement.
 *
 * The tests care as much about what survives as about what goes. Deleting the
 * photograph protects the signer; deleting the record that a photograph was
 * requested and agreed to would weaken the audit trail while protecting nobody,
 * since that record holds no personal data.
 */
class PurgeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(array $overrides = []): Recipient
    {
        $user = User::factory()->create();

        $document = Document::create([
            'owner_id' => $user->id,
            'filename' => 'agreement.pdf',
            'storage_key' => 'documents/test/' . uniqid() . '.pdf',
            'sha256_original' => str_repeat('f', 64),
            'page_count' => 1,
            'size_bytes' => 1024,
        ]);

        $envelope = Envelope::create([
            'document_id' => $document->id,
            'sender_id' => $user->id,
            'subject' => 'Agreement',
            'status' => Envelope::STATUS_COMPLETED,
        ]);

        $recipient = new Recipient(array_merge([
            'envelope_id' => $envelope->id,
            'name' => 'Sam Signer',
            'email' => 'sam@example.test',
            'role' => Recipient::ROLE_SIGNER,
            'routing_order' => 1,
            'status' => Recipient::STATUS_SIGNED,
        ], $overrides));
        $recipient->access_token_hash = hash('sha256', uniqid('', true));
        $recipient->save();

        return $recipient;
    }

    public function test_it_deletes_photographs_past_the_retention_period(): void
    {
        config(['signing.retention.photo_days' => 90]);
        Storage::fake(config('signing.storage_disk'));
        Storage::disk(config('signing.storage_disk'))->put('photos/1/old.jpg', 'image-bytes');

        $recipient = $this->recipient([
            'photo_consent' => 'granted',
            'photo_storage_key' => 'photos/1/old.jpg',
            'photo_sha256' => str_repeat('a', 64),
            'photo_captured_at' => Carbon::now('UTC')->subDays(120),
        ]);

        $this->artisan('signdesk:purge-evidence')->assertSuccessful();

        $fresh = $recipient->fresh();

        Storage::disk(config('signing.storage_disk'))->assertMissing('photos/1/old.jpg');
        $this->assertNull($fresh->photo_storage_key);
        $this->assertNull($fresh->photo_sha256);
        $this->assertFalse($fresh->hasPhoto());

        // The decision survives. It is the evidentially useful part and holds
        // no personal data.
        $this->assertSame('granted', $fresh->photo_consent);
        $this->assertNotNull($fresh->photo_captured_at);
    }

    public function test_it_leaves_photographs_inside_the_retention_period(): void
    {
        config(['signing.retention.photo_days' => 90]);
        Storage::fake(config('signing.storage_disk'));
        Storage::disk(config('signing.storage_disk'))->put('photos/1/recent.jpg', 'image-bytes');

        $recipient = $this->recipient([
            'photo_consent' => 'granted',
            'photo_storage_key' => 'photos/1/recent.jpg',
            'photo_sha256' => str_repeat('b', 64),
            'photo_captured_at' => Carbon::now('UTC')->subDays(10),
        ]);

        $this->artisan('signdesk:purge-evidence')->assertSuccessful();

        Storage::disk(config('signing.storage_disk'))->assertExists('photos/1/recent.jpg');
        $this->assertTrue($recipient->fresh()->hasPhoto());
    }

    public function test_it_clears_coordinates_but_keeps_the_consent_decision(): void
    {
        config(['signing.retention.location_days' => 365]);

        $recipient = $this->recipient([
            'location_consent' => 'granted',
            'latitude' => 25.2048,
            'longitude' => 55.2708,
            'location_accuracy_m' => 30,
            'location_captured_at' => Carbon::now('UTC')->subDays(400),
        ]);

        $this->artisan('signdesk:purge-evidence')->assertSuccessful();

        $fresh = $recipient->fresh();

        $this->assertNull($fresh->latitude);
        $this->assertNull($fresh->longitude);
        $this->assertSame('granted', $fresh->location_consent);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        config(['signing.retention.photo_days' => 30]);
        Storage::fake(config('signing.storage_disk'));
        Storage::disk(config('signing.storage_disk'))->put('photos/1/x.jpg', 'image-bytes');

        $recipient = $this->recipient([
            'photo_consent' => 'granted',
            'photo_storage_key' => 'photos/1/x.jpg',
            'photo_sha256' => str_repeat('c', 64),
            'photo_captured_at' => Carbon::now('UTC')->subDays(90),
        ]);

        $this->artisan('signdesk:purge-evidence', ['--dry-run' => true])->assertSuccessful();

        Storage::disk(config('signing.storage_disk'))->assertExists('photos/1/x.jpg');
        $this->assertTrue($recipient->fresh()->hasPhoto());
    }

    public function test_retention_can_be_disabled(): void
    {
        config(['signing.retention.photo_days' => 0, 'signing.retention.location_days' => 0]);
        Storage::fake(config('signing.storage_disk'));
        Storage::disk(config('signing.storage_disk'))->put('photos/1/keep.jpg', 'image-bytes');

        $this->recipient([
            'photo_consent' => 'granted',
            'photo_storage_key' => 'photos/1/keep.jpg',
            'photo_sha256' => str_repeat('d', 64),
            'photo_captured_at' => Carbon::now('UTC')->subYears(5),
        ]);

        $this->artisan('signdesk:purge-evidence')->assertSuccessful();

        Storage::disk(config('signing.storage_disk'))->assertExists('photos/1/keep.jpg');
    }

    public function test_the_purge_is_written_to_the_audit_trail(): void
    {
        config(['signing.retention.photo_days' => 30]);
        Storage::fake(config('signing.storage_disk'));
        Storage::disk(config('signing.storage_disk'))->put('photos/1/y.jpg', 'image-bytes');

        $recipient = $this->recipient([
            'photo_consent' => 'granted',
            'photo_storage_key' => 'photos/1/y.jpg',
            'photo_sha256' => str_repeat('e', 64),
            'photo_captured_at' => Carbon::now('UTC')->subDays(60),
        ]);

        $this->artisan('signdesk:purge-evidence')->assertSuccessful();

        // Deleting quietly would leave a certificate describing a photograph
        // that no longer exists, with nothing accounting for the gap.
        $event = AuditEvent::where('type', AuditEvent::EVIDENCE_PURGED)->firstOrFail();

        $this->assertSame('photograph', $event->payload['what']);
        $this->assertSame(30, $event->payload['retention_days']);
        $this->assertSame($recipient->envelope_id, $event->envelope_id);
    }
}
