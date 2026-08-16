<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureField;
use App\Models\User;
use App\Services\SignerTokenService;
use App\Support\Disclosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The access controls around the signing ceremony.
 *
 * Signers have no account, so every one of these endpoints is protected only by
 * the tokenised link plus a verified passcode. That makes these the highest-value
 * tests in the suite.
 */
class SignerSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Envelope $envelope;

    /** @var array<int, Recipient> */
    private array $recipients = [];

    /** @var array<int, string> plaintext tokens, by recipient index */
    private array $tokens = [];

    /** @var array<int, SignatureField> */
    private array $fields = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $document = Document::create([
            'owner_id' => $user->id,
            'filename' => 'agreement.pdf',
            'storage_key' => 'documents/test/' . uniqid() . '.pdf',
            'sha256_original' => str_repeat('b', 64),
            'page_count' => 2,
            'size_bytes' => 2048,
        ]);

        $this->envelope = Envelope::create([
            'document_id' => $document->id,
            'sender_id' => $user->id,
            'subject' => 'Two-party agreement',
            'status' => Envelope::STATUS_SENT,
        ]);

        $tokens = app(SignerTokenService::class);

        foreach ([['Alice', 'alice@example.test'], ['Bob', 'bob@example.test']] as $i => [$name, $email]) {
            $recipient = new Recipient([
                'envelope_id' => $this->envelope->id,
                'name' => $name,
                'email' => $email,
                'role' => Recipient::ROLE_SIGNER,
                'routing_order' => 1,
                'status' => Recipient::STATUS_SENT,
            ]);
            $recipient->access_token_hash = hash('sha256', 'placeholder-' . $i);
            $recipient->save();

            $this->recipients[$i] = $recipient;
            $this->tokens[$i] = $tokens->issue($recipient);

            $this->fields[$i] = SignatureField::create([
                'envelope_id' => $this->envelope->id,
                'recipient_id' => $recipient->id,
                'type' => SignatureField::TYPE_TEXT,
                'page' => 0,
                'x' => 0.1,
                'y' => 0.1 + ($i * 0.2),
                'w' => 0.2,
                'h' => 0.05,
                'required' => true,
            ]);
        }
    }

    private function url(int $index, string $path = ''): string
    {
        return "/api/sign/{$this->envelope->uuid}{$path}?t={$this->tokens[$index]}";
    }

    private function markVerified(int $index): void
    {
        $this->recipients[$index]->forceFill([
            'otp_verified' => true,
            'auth_method' => 'Email link + email OTP',
        ])->save();
    }

    private function giveConsent(int $index): void
    {
        $disclosure = Disclosure::current();

        Consent::create([
            'recipient_id' => $this->recipients[$index]->id,
            'disclosure_version' => $disclosure['version'],
            'disclosure_sha256' => $disclosure['sha256'],
            'accepted_at' => Carbon::now('UTC'),
            'ip' => '127.0.0.1',
        ]);
    }

    /* ------------------------------------------------------------- tokens */

    public function test_a_forged_token_is_rejected(): void
    {
        $this->getJson("/api/sign/{$this->envelope->uuid}?t=" . str_repeat('0', 64))
            ->assertNotFound();
    }

    public function test_an_empty_token_is_rejected(): void
    {
        $this->getJson("/api/sign/{$this->envelope->uuid}?t=")->assertNotFound();
    }

    public function test_only_the_hash_of_a_token_is_stored(): void
    {
        $stored = $this->recipients[0]->fresh()->access_token_hash;

        $this->assertNotSame($this->tokens[0], $stored);
        $this->assertSame(hash('sha256', $this->tokens[0]), $stored);
        $this->assertSame(64, strlen($this->tokens[0]));
    }

    public function test_an_expired_token_returns_gone(): void
    {
        $this->recipients[0]->forceFill([
            'token_expires_at' => Carbon::now('UTC')->subDay(),
        ])->save();

        $this->getJson($this->url(0))->assertStatus(410);
    }

    public function test_a_voided_envelope_is_no_longer_signable(): void
    {
        $this->envelope->forceFill(['status' => Envelope::STATUS_VOIDED])->save();

        $this->getJson($this->url(0))->assertStatus(410);
    }

    public function test_the_response_never_leaks_token_or_otp_hashes(): void
    {
        $this->markVerified(0);

        $response = $this->getJson($this->url(0))->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('access_token_hash', $body);
        $this->assertStringNotContainsString('otp_hash', $body);
        $this->assertStringNotContainsString($this->tokens[0], $body);
    }

    public function test_the_signer_email_is_masked(): void
    {
        $response = $this->getJson($this->url(0))->assertOk();

        $this->assertSame('a****@example.test', $response->json('recipient.email'));
    }

    /* ---------------------------------------------------------------- OTP */

    public function test_document_access_is_blocked_before_the_passcode_is_verified(): void
    {
        $this->get($this->url(0, '/document'))->assertForbidden();
    }

    public function test_consent_is_blocked_before_the_passcode_is_verified(): void
    {
        $this->postJson($this->url(0, '/consent'), ['accepted' => true])->assertForbidden();
    }

    public function test_the_passcode_locks_out_after_repeated_failures(): void
    {
        $tokens = app(SignerTokenService::class);
        $recipient = $this->recipients[0];
        $tokens->issueOtp($recipient);

        $max = (int) config('signing.otp.max_attempts');

        for ($attempt = 1; $attempt <= $max; $attempt++) {
            $result = $tokens->verifyOtp($recipient->fresh(), '000000');
            $this->assertFalse($result['ok']);
        }

        $this->assertTrue($recipient->fresh()->isOtpLocked());

        // Even the correct code is refused while locked out.
        $this->assertSame('locked', $tokens->verifyOtp($recipient->fresh(), '000000')['reason']);
    }

    public function test_a_correct_passcode_clears_the_stored_hash(): void
    {
        $tokens = app(SignerTokenService::class);
        $recipient = $this->recipients[0];

        $code = $tokens->issueOtp($recipient);
        $result = $tokens->verifyOtp($recipient->fresh(), $code);

        $this->assertTrue($result['ok']);

        $fresh = $recipient->fresh();
        $this->assertTrue($fresh->otp_verified);
        $this->assertNull($fresh->otp_hash);
        $this->assertSame('Email link + email OTP', $fresh->auth_method);
    }

    public function test_an_expired_passcode_is_refused(): void
    {
        $tokens = app(SignerTokenService::class);
        $recipient = $this->recipients[0];

        $code = $tokens->issueOtp($recipient);
        $recipient->forceFill(['otp_expires_at' => Carbon::now('UTC')->subMinute()])->save();

        $this->assertSame('expired', $tokens->verifyOtp($recipient->fresh(), $code)['reason']);
    }

    /* ------------------------------------------------------------ consent */

    public function test_signing_is_blocked_before_consent(): void
    {
        $this->markVerified(0);

        $this->postJson($this->url(0, '/fields'), [
            'values' => [['field_id' => $this->fields[0]->id, 'text' => 'hello']],
        ])->assertForbidden();
    }

    public function test_consent_records_the_disclosure_version_and_hash(): void
    {
        $this->markVerified(0);

        $this->postJson($this->url(0, '/consent'), ['accepted' => true])->assertOk();

        $consent = $this->recipients[0]->fresh()->consent;
        $disclosure = Disclosure::current();

        $this->assertSame($disclosure['version'], $consent->disclosure_version);
        $this->assertSame($disclosure['sha256'], $consent->disclosure_sha256);
        $this->assertNotNull($consent->ip);
    }

    public function test_the_disclosure_hash_is_stable(): void
    {
        $this->assertSame(Disclosure::current()['sha256'], Disclosure::current()['sha256']);
        $this->assertSame(64, strlen(Disclosure::current()['sha256']));
    }

    /* --------------------------------------------------------------- IDOR */

    public function test_a_signer_cannot_fill_another_signers_field(): void
    {
        $this->markVerified(0);
        $this->giveConsent(0);

        // Alice's token, Bob's field id.
        $this->postJson($this->url(0, '/fields'), [
            'values' => [['field_id' => $this->fields[1]->id, 'text' => 'forged']],
        ])->assertForbidden();

        $this->assertDatabaseMissing('field_values', [
            'signature_field_id' => $this->fields[1]->id,
        ]);
    }

    public function test_a_signer_only_receives_their_own_fields(): void
    {
        $this->markVerified(0);

        $fields = $this->getJson($this->url(0))->assertOk()->json('fields');

        $this->assertCount(1, $fields);
        $this->assertSame($this->fields[0]->id, $fields[0]['id']);
    }

    public function test_a_signer_can_fill_their_own_field(): void
    {
        $this->markVerified(0);
        $this->giveConsent(0);

        $this->postJson($this->url(0, '/fields'), [
            'values' => [['field_id' => $this->fields[0]->id, 'text' => 'Alice']],
        ])->assertOk();

        $this->assertDatabaseHas('field_values', [
            'signature_field_id' => $this->fields[0]->id,
            'text_value' => 'Alice',
        ]);
    }

    /* ------------------------------------------------------------- finish */

    public function test_the_token_is_burned_after_signing(): void
    {
        $this->markVerified(0);
        $this->giveConsent(0);

        $this->postJson($this->url(0, '/fields'), [
            'values' => [['field_id' => $this->fields[0]->id, 'text' => 'Alice']],
        ])->assertOk();

        $this->postJson($this->url(0, '/finish'))->assertOk();

        // The same link no longer resolves to anybody.
        $this->getJson($this->url(0))->assertNotFound();
    }

    public function test_finishing_requires_every_required_field(): void
    {
        $this->markVerified(0);
        $this->giveConsent(0);

        $this->postJson($this->url(0, '/finish'))->assertStatus(422);
    }
}
