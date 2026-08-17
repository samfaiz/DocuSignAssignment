<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use App\Services\SignerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Optional photograph at signing.
 *
 * A face image is biometric data, so the tests here are about restraint as much
 * as function: it is only ever collected when a sender asked for it, declining
 * is recorded rather than punished, the image never leaks into the audit trail
 * every party can read, and nothing in the system calls it verification.
 */
class SigningPhotoTest extends TestCase
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
            'sha256_original' => str_repeat('d', 64),
            'page_count' => 1,
            'size_bytes' => 1024,
        ]);

        $this->envelope = Envelope::create([
            'document_id' => $document->id,
            'sender_id' => $user->id,
            'subject' => 'Agreement',
            'status' => Envelope::STATUS_SENT,
            'require_photo' => true,
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
        return "/api/sign/{$this->envelope->uuid}/photo?t={$this->token}";
    }

    public function test_it_is_refused_when_the_sender_did_not_ask_for_one(): void
    {
        $this->envelope->forceFill(['require_photo' => false])->save();

        $this->postJson($this->url(), ['consent' => 'denied'])->assertStatus(422);
    }

    public function test_it_requires_a_verified_passcode(): void
    {
        $this->recipient->forceFill(['otp_verified' => false])->save();

        $this->postJson($this->url(), ['consent' => 'denied'])->assertForbidden();
    }

    public function test_declining_is_recorded_and_stores_no_image(): void
    {
        $this->postJson($this->url(), ['consent' => 'denied'])->assertOk();

        $recipient = $this->recipient->fresh();

        $this->assertSame('denied', $recipient->photo_consent);
        $this->assertNull($recipient->photo_storage_key);
        $this->assertFalse($recipient->hasPhoto());
        $this->assertSame('Declined by signer', $recipient->photoSummary());

        $this->assertDatabaseHas('audit_events', [
            'envelope_id' => $this->envelope->id,
            'type' => AuditEvent::RECIPIENT_PHOTO,
        ]);
    }

    public function test_a_captured_photo_is_stored_and_hashed(): void
    {
        $this->skipUnlessSignServiceIsRunning();
        Storage::fake(config('signing.storage_disk'));

        $this->postJson($this->url(), [
            'consent' => 'granted',
            'image' => 'data:image/jpeg;base64,' . base64_encode($this->sampleJpeg()),
        ])->assertOk();

        $recipient = $this->recipient->fresh();

        $this->assertSame('granted', $recipient->photo_consent);
        $this->assertTrue($recipient->hasPhoto());
        $this->assertSame(64, strlen($recipient->photo_sha256));
        Storage::disk(config('signing.storage_disk'))->assertExists($recipient->photo_storage_key);
    }

    public function test_the_audit_trail_records_the_hash_but_never_the_image(): void
    {
        $this->skipUnlessSignServiceIsRunning();
        Storage::fake(config('signing.storage_disk'));

        $this->postJson($this->url(), [
            'consent' => 'granted',
            'image' => 'data:image/jpeg;base64,' . base64_encode($this->sampleJpeg()),
        ])->assertOk();

        $event = AuditEvent::where('type', AuditEvent::RECIPIENT_PHOTO)->firstOrFail();
        $payload = json_encode($event->payload);

        $this->assertSame(64, strlen($event->payload['sha256']));
        // The trail is shown to every party and printed into the certificate.
        // One signer's face is not something the others need a copy of.
        $this->assertStringNotContainsString('base64', $payload);
        $this->assertLessThan(500, strlen($payload));
    }

    public function test_nothing_describes_a_photograph_as_verified_identity(): void
    {
        $this->recipient->forceFill([
            'photo_consent' => 'granted',
            'photo_storage_key' => 'photos/1/x.jpg',
            'photo_sha256' => str_repeat('e', 64),
        ])->save();

        $summary = $this->recipient->fresh()->photoSummary();

        $this->assertStringContainsString('not identity-verified', $summary);
        $this->assertStringNotContainsString('verified identity', $summary);
    }

    private function sampleJpeg(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 120, 160));

        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function skipUnlessSignServiceIsRunning(): void
    {
        try {
            $healthy = Http::timeout(3)
                ->get(rtrim((string) config('signing.service.url'), '/') . '/health')
                ->successful();
        } catch (\Throwable) {
            $healthy = false;
        }

        if (! $healthy) {
            $this->markTestSkipped('The sealing service is not running.');
        }
    }
}
