<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SigningInvitation;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureField;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SignerTokenService;
use App\Services\SignServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Convenience endpoints for someone evaluating this project.
 *
 * Provisions a ready-to-sign envelope and reads the one-time passcode back, so a
 * reviewer can exercise the whole ceremony without a terminal or a mail catcher.
 *
 * These routes only register when demo mode is on, and the constructor refuses
 * to build otherwise — reading a live passcode over HTTP would let anyone who
 * can reach the API complete somebody else's signature.
 */
class DemoController extends Controller
{
    public function __construct(
        private readonly SignServiceClient $signService,
        private readonly SignerTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {
        abort_unless(config('signing.demo.enabled'), 404);
    }

    /** Builds a fresh envelope, sends it, and hands back a live signing link. */
    public function provision(Request $request): JsonResponse
    {
        // updateOrCreate rather than firstOrCreate: the password is published on
        // the demo page, so it has to be the password that actually works. This
        // account is separate from any real administrator by design.
        $admin = User::updateOrCreate(
            ['email' => config('signing.demo.admin_email')],
            [
                'name' => 'SignDesk Demo',
                'password' => Hash::make(config('signing.demo.admin_password')),
                'email_verified_at' => now(),
            ]
        );

        $path = config('signing.demo.sample_pdf');
        abort_unless(is_string($path) && is_file($path), 500, 'Demo sample PDF is missing.');

        $contents = file_get_contents($path);

        // Deliberately the same path a real upload takes — validated by a real
        // parser, hashed, stored under a random key — so what the reviewer
        // exercises is the production flow, not a shortcut around it.
        $info = $this->signService->inspect($contents);

        $key = sprintf('documents/%d/%s.pdf', $admin->id, Str::uuid());
        Storage::disk(config('signing.storage_disk'))->put($key, $contents);

        $envelope = DB::transaction(function () use ($admin, $key, $info) {
            $document = Document::create([
                'owner_id' => $admin->id,
                'filename' => 'consulting-agreement.pdf',
                'storage_key' => $key,
                'sha256_original' => $info['sha256'],
                'page_count' => $info['page_count'],
                'size_bytes' => $info['size_bytes'],
            ]);

            $envelope = Envelope::create([
                'document_id' => $document->id,
                'sender_id' => $admin->id,
                'subject' => 'Consulting Services Agreement',
                'message' => 'Please review and sign at your convenience.',
                'status' => Envelope::STATUS_DRAFT,
                'expires_at' => Carbon::now('UTC')->addDays(30),
            ]);

            $recipient = new Recipient([
                'envelope_id' => $envelope->id,
                'name' => 'Alex Reviewer',
                'email' => 'reviewer@example.test',
                'role' => Recipient::ROLE_SIGNER,
                'routing_order' => 1,
                'status' => Recipient::STATUS_PENDING,
            ]);
            $recipient->access_token_hash = hash('sha256', bin2hex(random_bytes(32)));
            $recipient->save();

            foreach ([
                ['type' => 'signature', 'x' => 0.085, 'y' => 0.815, 'w' => 0.26, 'h' => 0.055],
                ['type' => 'date', 'x' => 0.085, 'y' => 0.882, 'w' => 0.26, 'h' => 0.022],
            ] as $field) {
                SignatureField::create([
                    'envelope_id' => $envelope->id,
                    'recipient_id' => $recipient->id,
                    'type' => $field['type'],
                    'page' => 0,
                    'x' => $field['x'],
                    'y' => $field['y'],
                    'w' => $field['w'],
                    'h' => $field['h'],
                    'required' => true,
                ]);
            }

            return $envelope;
        });

        $this->audit->record($envelope, AuditEvent::ENVELOPE_CREATED, [
            'document' => 'consulting-agreement.pdf',
            'sha256_original' => $info['sha256'],
            'source' => 'reviewer demo',
        ], request: $request, actor: $admin->email);

        $envelope->forceFill([
            'status' => Envelope::STATUS_SENT,
            'sent_at' => Carbon::now('UTC'),
        ])->save();

        $this->audit->record($envelope, AuditEvent::ENVELOPE_SENT, [
            'subject' => $envelope->subject,
        ], request: $request, actor: $admin->email);

        $recipient = $envelope->recipients()->first();
        $token = $this->tokens->issue($recipient);
        $url = $this->tokens->signingUrl($recipient, $token);

        $recipient->forceFill(['status' => Recipient::STATUS_SENT])->save();

        // Still queued, so the invitation is visible in Mailpit for anyone who
        // wants to check that the real delivery path works.
        Mail::to($recipient->email)->queue(new SigningInvitation($envelope, $recipient, $url));

        $this->audit->record($envelope, AuditEvent::RECIPIENT_EMAIL_SENT, [
            'to' => $recipient->email,
        ], recipient: $recipient, request: $request, actor: $admin->email);

        return response()->json([
            'sign_url' => $url,
            'envelope_uuid' => $envelope->uuid,
            'token' => $token,
            'admin' => $this->credentials(),
        ], 201);
    }

    /**
     * The throwaway credentials the demo page displays.
     *
     * Served rather than hard-coded in the frontend so the page can never
     * advertise a login that does not work — and so a real administrator's
     * password is never the thing on screen.
     */
    public function info(): JsonResponse
    {
        return response()->json([
            'admin' => $this->credentials(),
            'mail_configured' => \App\Models\MailSetting::current()->isConfigured(),
        ]);
    }

    private function credentials(): array
    {
        return [
            'email' => config('signing.demo.admin_email'),
            'password' => config('signing.demo.admin_password'),
        ];
    }

    /**
     * Reads back the current passcode.
     *
     * Requires the signing token, so it discloses nothing the caller could not
     * already get from the recipient's inbox — but it is still a live factor,
     * hence the demo gate.
     */
    public function otp(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->tokens->resolve($uuid, (string) $request->query('t', ''));

        abort_unless($recipient, 404, 'This signing link is not valid.');

        $code = Cache::get(SignerTokenService::demoOtpKey($recipient));

        return response()->json([
            'code' => $code,
            'message' => $code
                ? 'Demo mode: in production this only ever reaches the signer\'s inbox.'
                : 'No passcode has been issued yet, or it has expired.',
        ]);
    }
}
