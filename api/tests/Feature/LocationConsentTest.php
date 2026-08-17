<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use App\Services\SignerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Optional location capture.
 *
 * The point of these tests is that the feature stays optional and honest:
 * declining is recorded rather than ignored, nothing about signing depends on
 * it, and the coordinates are never presented as something the server verified.
 */
class LocationConsentTest extends TestCase
{
    use RefreshDatabase;

    private Envelope $envelope;

    private Recipient $recipient;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $document = Document::create([
            'owner_id' => $user->id,
            'filename' => 'agreement.pdf',
            'storage_key' => 'documents/test/' . uniqid() . '.pdf',
            'sha256_original' => str_repeat('c', 64),
            'page_count' => 1,
            'size_bytes' => 1024,
        ]);

        $this->envelope = Envelope::create([
            'document_id' => $document->id,
            'sender_id' => $user->id,
            'subject' => 'Agreement',
            'status' => Envelope::STATUS_SENT,
        ]);

        $this->recipient = new Recipient([
            'envelope_id' => $this->envelope->id,
            'name' => 'Sam Signer',
            'email' => 'sam@example.test',
            'role' => Recipient::ROLE_SIGNER,
            'routing_order' => 1,
            'status' => Recipient::STATUS_SENT,
        ]);
        $this->recipient->access_token_hash = hash('sha256', 'placeholder');
        $this->recipient->save();

        $this->token = app(SignerTokenService::class)->issue($this->recipient);

        $this->recipient->forceFill([
            'otp_verified' => true,
            'auth_method' => 'Email link + email OTP',
        ])->save();
    }

    private function url(): string
    {
        return "/api/sign/{$this->envelope->uuid}/location?t={$this->token}";
    }

    public function test_it_requires_a_verified_passcode(): void
    {
        $this->recipient->forceFill(['otp_verified' => false])->save();

        $this->postJson($this->url(), ['consent' => 'denied'])->assertForbidden();
    }

    public function test_it_records_shared_coordinates(): void
    {
        $this->postJson($this->url(), [
            'consent' => 'granted',
            'latitude' => 25.2048,
            'longitude' => 55.2708,
            'accuracy' => 32.5,
        ])->assertOk();

        $recipient = $this->recipient->fresh();

        $this->assertSame('granted', $recipient->location_consent);
        $this->assertEqualsWithDelta(25.2048, $recipient->latitude, 0.00001);
        $this->assertEqualsWithDelta(55.2708, $recipient->longitude, 0.00001);
        $this->assertSame(33, $recipient->location_accuracy_m);
        $this->assertStringContainsString('reported', $recipient->locationSummary());
    }

    public function test_declining_is_recorded_rather_than_ignored(): void
    {
        $this->postJson($this->url(), ['consent' => 'denied'])->assertOk();

        $recipient = $this->recipient->fresh();

        $this->assertSame('denied', $recipient->location_consent);
        $this->assertNull($recipient->latitude);
        $this->assertSame('Declined by signer', $recipient->locationSummary());

        // A recorded refusal is evidence; silence would not be.
        $this->assertDatabaseHas('audit_events', [
            'envelope_id' => $this->envelope->id,
            'type' => AuditEvent::RECIPIENT_LOCATION,
        ]);
    }

    public function test_the_audit_trail_keeps_only_coarse_coordinates(): void
    {
        $this->postJson($this->url(), [
            'consent' => 'granted',
            'latitude' => 25.2048372,
            'longitude' => 55.2707872,
            'accuracy' => 10,
        ])->assertOk();

        $event = AuditEvent::where('type', AuditEvent::RECIPIENT_LOCATION)->firstOrFail();

        // Two decimal places is around a kilometre. The trail is shown to every
        // party and printed into the certificate, so it should not repeat a
        // metre-accurate position.
        $this->assertSame('25.20, 55.27', $event->payload['approximate']);
        $this->assertStringNotContainsString('25.2048372', json_encode($event->payload));
    }

    public function test_coordinates_are_validated(): void
    {
        $this->postJson($this->url(), [
            'consent' => 'granted', 'latitude' => 999, 'longitude' => 55,
        ])->assertStatus(422);

        $this->postJson($this->url(), [
            'consent' => 'granted', 'latitude' => 25, 'longitude' => -500,
        ])->assertStatus(422);

        $this->postJson($this->url(), ['consent' => 'granted'])->assertStatus(422);

        $this->postJson($this->url(), ['consent' => 'maybe'])->assertStatus(422);
    }

    public function test_signing_does_not_depend_on_sharing_a_location(): void
    {
        // Never asked, never shared - and the record still describes that state.
        $this->assertSame('not_asked', $this->recipient->location_consent);
        $this->assertSame('Not requested', $this->recipient->locationSummary());
        $this->assertFalse($this->recipient->hasLocation());
    }
}
