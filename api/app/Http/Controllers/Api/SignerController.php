<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SealEnvelope;
use App\Mail\SigningOtp;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Envelope;
use App\Models\FieldValue;
use App\Models\Recipient;
use App\Models\SignatureAsset;
use App\Models\SignatureField;
use App\Services\AuditLogger;
use App\Services\SignerTokenService;
use App\Services\SignServiceClient;
use App\Support\Disclosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signing ceremony.
 *
 * Every endpoint is authenticated purely by the tokenised link plus, past the
 * first step, a verified one-time passcode. There is no session and no account:
 * signers are not users of the system, which is exactly why each request has to
 * re-establish who is calling and what they are allowed to touch.
 */
class SignerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SignerTokenService $tokens,
        private readonly SignServiceClient $signService,
    ) {
    }

    /** Landing payload: only ever this recipient's own fields. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->resolve($request, $uuid);
        $envelope = $recipient->envelope;

        if ($recipient->viewed_at === null) {
            $recipient->forceFill([
                'viewed_at' => Carbon::now('UTC'),
                'status' => Recipient::STATUS_VIEWED,
            ])->save();

            $this->audit->record($envelope, AuditEvent::RECIPIENT_OPENED, [
                'recipient' => $recipient->email,
            ], recipient: $recipient, request: $request, actor: $recipient->email);
        }

        $recipient->forceFill([
            'last_ip' => $request->ip(),
            'last_user_agent' => $request->userAgent(),
        ])->save();

        return response()->json([
            'envelope' => [
                'uuid' => $envelope->uuid,
                'subject' => $envelope->subject,
                'message' => $envelope->message,
                'status' => $envelope->status,
                'expires_at' => $envelope->expires_at,
                'sender' => $envelope->sender->name,
                'document' => [
                    'filename' => $envelope->document->filename,
                    'page_count' => $envelope->document->page_count,
                ],
            ],
            'recipient' => [
                'name' => $recipient->name,
                'email' => $this->maskEmail($recipient->email),
                'role' => $recipient->role,
                'status' => $recipient->status,
                'signed_at' => $recipient->signed_at,
                'otp_verified' => $recipient->otp_verified,
                'has_consented' => $recipient->consent !== null,
                'location_consent' => $recipient->location_consent,
            ],
            // Scoped to this recipient. Another signer's fields are not merely
            // hidden in the UI — they never leave the database.
            'fields' => $recipient->fields()->with('value')->orderBy('page')->get(),
            'my_turn' => $this->isTheirTurn($recipient),
            'fonts' => config('signing.fonts'),
            'disclosure' => Disclosure::current(),
        ]);
    }

    public function requestOtp(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->resolve($request, $uuid);

        // Independent of the per-token throttle: without this, resending is a
        // free mail-bomb aimed at the recipient's inbox.
        $key = 'otp-send:' . $recipient->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => 'Too many codes requested. Try again in '
                    . RateLimiter::availableIn($key) . ' seconds.',
            ], 429);
        }
        RateLimiter::hit($key, 900);

        if ($recipient->isOtpLocked()) {
            return response()->json([
                'message' => 'Too many incorrect codes. This link is locked temporarily.',
            ], 423);
        }

        $code = $this->tokens->issueOtp($recipient);
        Mail::to($recipient->email)->queue(new SigningOtp($recipient, $code));

        // The code itself is never logged. An audit trail that records the
        // credential would defeat the purpose of having one.
        $this->audit->record($recipient->envelope, AuditEvent::RECIPIENT_OTP_SENT, [
            'to' => $this->maskEmail($recipient->email),
        ], recipient: $recipient, request: $request, actor: $recipient->email);

        return response()->json([
            'message' => 'A verification code has been sent to your email address.',
            'expires_in_minutes' => (int) config('signing.otp.ttl_minutes'),
        ]);
    }

    public function verifyOtp(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->resolve($request, $uuid);

        $code = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ])['code'];

        $result = $this->tokens->verifyOtp($recipient, $code);

        if (! $result['ok']) {
            $this->audit->record($recipient->envelope, AuditEvent::RECIPIENT_OTP_FAILED, [
                'reason' => $result['reason'],
                'attempts' => $recipient->fresh()->otp_attempts,
            ], recipient: $recipient, request: $request, actor: $recipient->email);

            return response()->json([
                'message' => match ($result['reason']) {
                    'locked' => 'Too many incorrect codes. Try again later.',
                    'expired' => 'That code has expired. Request a new one.',
                    'not_issued' => 'Request a code first.',
                    default => 'That code is not correct.',
                },
            ], $result['reason'] === 'locked' ? 423 : 422);
        }

        $this->audit->record($recipient->envelope, AuditEvent::RECIPIENT_OTP_VERIFIED, [
            'method' => 'Email link + email OTP',
        ], recipient: $recipient, request: $request, actor: $recipient->email);

        return response()->json(['message' => 'Verified.']);
    }

    public function consent(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->requireVerified($request, $uuid);

        $request->validate([
            'accepted' => ['required', 'accepted'],
        ]);

        $disclosure = Disclosure::current();

        // The version and a hash of the exact text are stored, not a boolean.
        // What matters in a dispute is which words were on screen.
        Consent::updateOrCreate(
            [
                'recipient_id' => $recipient->id,
                'disclosure_version' => $disclosure['version'],
            ],
            [
                'disclosure_sha256' => $disclosure['sha256'],
                'accepted_at' => Carbon::now('UTC'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        $this->audit->record($recipient->envelope, AuditEvent::RECIPIENT_CONSENTED, [
            'disclosure_version' => $disclosure['version'],
            'disclosure_sha256' => $disclosure['sha256'],
        ], recipient: $recipient, request: $request, actor: $recipient->email);

        return response()->json(['message' => 'Consent recorded.']);
    }

    /**
     * Record the signer's decision about sharing their location.
     *
     * Always optional. Refusing must never block signing — conditioning a
     * signature on surrendering location data would be coercive, and a consent
     * given under that condition is worth very little as evidence anyway.
     *
     * The decision is recorded either way. "Declined" is a meaningful entry in
     * the trail; it is the absence of any entry that tells you nothing.
     */
    public function shareLocation(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->requireVerified($request, $uuid);

        $data = $request->validate([
            'consent' => ['required', 'in:granted,denied,unsupported,failed'],
            'latitude' => ['required_if:consent,granted', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:consent,granted', 'nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $granted = $data['consent'] === Recipient::LOCATION_GRANTED;

        $recipient->forceFill([
            'location_consent' => $data['consent'],
            'latitude' => $granted ? $data['latitude'] : null,
            'longitude' => $granted ? $data['longitude'] : null,
            'location_accuracy_m' => $granted && isset($data['accuracy'])
                ? (int) round($data['accuracy'])
                : null,
            'location_captured_at' => Carbon::now('UTC'),
        ])->save();

        // Coordinates are logged rounded. The full precision stays on the
        // recipient record for the certificate; the audit trail is shown to
        // every party, and a metre-accurate home address does not need to be
        // repeated in a document everyone receives.
        $this->audit->record($recipient->envelope, AuditEvent::RECIPIENT_LOCATION, [
            'consent' => $data['consent'],
            'source' => 'browser geolocation',
            'reported' => $granted,
            'approximate' => $granted
                ? sprintf('%.2f, %.2f', $data['latitude'], $data['longitude'])
                : null,
            'accuracy_m' => $granted ? ($data['accuracy'] ?? null) : null,
        ], recipient: $recipient, request: $request, actor: $recipient->email);

        return response()->json([
            'location_consent' => $recipient->location_consent,
            'summary' => $recipient->locationSummary(),
        ]);
    }

    /** Create a signature artefact: drawn, uploaded, or typed. */
    public function createSignature(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->requireVerified($request, $uuid);

        $data = $request->validate([
            'kind' => ['required', 'in:drawn,uploaded,typed'],
            'image' => ['required_if:kind,drawn,uploaded', 'nullable', 'string'],
            'name' => ['required_if:kind,typed', 'nullable', 'string', 'max:120'],
            'font' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            if ($data['kind'] === SignatureAsset::KIND_TYPED) {
                $font = $data['font'] ?? 'great-vibes';
                abort_unless(
                    array_key_exists($font, config('signing.fonts')),
                    422,
                    'Unknown signature font.'
                );

                // Rendered on the server so the artefact sealed into the PDF is
                // the same one we hash here, regardless of the signer's fonts.
                $result = $this->signService->typedSignature($data['name'], $font);
            } else {
                $raw = $this->decodeImage($data['image']);
                $font = null;

                // Full decode and re-encode. Whatever was attached to the
                // original file — EXIF, trailing bytes, a polyglot payload —
                // does not survive being turned back into pixels.
                $result = $this->signService->sanitizeSignature($raw);
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'image' => 'That signature image could not be processed.',
            ]);
        }

        $png = base64_decode($result['png_b64']);
        $key = sprintf('signatures/%d/%s.png', $recipient->id, Str::uuid());
        Storage::disk(config('signing.storage_disk'))->put($key, $png);

        $asset = SignatureAsset::create([
            'recipient_id' => $recipient->id,
            'kind' => $data['kind'],
            'storage_key' => $key,
            'sha256' => $result['sha256'],
            'font_family' => $font,
        ]);

        return response()->json([
            'asset' => $asset,
            // The server-rendered artefact, returned so the signer previews the
            // exact image that will be sealed into the document rather than a
            // client-side approximation of it. This matters most for typed
            // signatures, where the browser has no rendering of its own.
            'preview' => 'data:image/png;base64,' . $result['png_b64'],
        ], 201);
    }

    /** Fill this recipient's fields. */
    public function saveFields(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->requireVerified($request, $uuid);
        $this->requireConsent($recipient);
        $this->requireSignable($recipient);

        $data = $request->validate([
            'values' => ['required', 'array', 'min:1'],
            'values.*.field_id' => ['required', 'integer'],
            'values.*.text' => ['nullable', 'string', 'max:500'],
            'values.*.asset_id' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($data, $recipient, $request) {
            foreach ($data['values'] as $row) {
                // Scoped by recipient_id, so a guessed or copied field id from
                // another signer's half of the document resolves to nothing.
                $field = SignatureField::where('id', $row['field_id'])
                    ->where('recipient_id', $recipient->id)
                    ->first();

                abort_unless($field, 403, 'That field does not belong to you.');

                $assetId = null;
                if (! empty($row['asset_id'])) {
                    $asset = SignatureAsset::where('id', $row['asset_id'])
                        ->where('recipient_id', $recipient->id)
                        ->first();
                    abort_unless($asset, 403, 'That signature does not belong to you.');
                    $assetId = $asset->id;
                }

                if ($field->expectsImage() && ! $assetId) {
                    abort(422, 'Signature fields need a signature image.');
                }

                FieldValue::updateOrCreate(
                    ['signature_field_id' => $field->id],
                    ['text_value' => $row['text'] ?? null, 'signature_asset_id' => $assetId]
                );

                $this->audit->record(
                    $recipient->envelope,
                    AuditEvent::RECIPIENT_FIELD_COMPLETED,
                    [
                        'field_id' => $field->id,
                        'type' => $field->type,
                        'page' => $field->page,
                    ],
                    recipient: $recipient,
                    request: $request,
                    actor: $recipient->email
                );
            }
        });

        return response()->json([
            'fields' => $recipient->fields()->with('value')->orderBy('page')->get(),
        ]);
    }

    /** Complete this signer's part; seal the envelope if everyone is done. */
    public function finish(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->requireVerified($request, $uuid);
        $this->requireConsent($recipient);
        $this->requireSignable($recipient);

        $missing = $recipient->fields()
            ->where('required', true)
            ->whereDoesntHave('value')
            ->count();

        abort_if($missing > 0, 422, "{$missing} required field(s) are still empty.");

        $envelope = $recipient->envelope;

        $recipient->forceFill([
            'status' => Recipient::STATUS_SIGNED,
            'signed_at' => Carbon::now('UTC'),
            'last_ip' => $request->ip(),
            'last_user_agent' => $request->userAgent(),
        ])->save();

        // The explicit affirmative act. UETA and the ESIGN Act both turn on the
        // signature being executed *with intent to sign*, so this is recorded
        // as its own event, distinct from merely having filled the fields in.
        $this->audit->record($envelope, AuditEvent::RECIPIENT_SIGNED, [
            'recipient' => $recipient->email,
            'auth_method' => $recipient->auth_method,
            'intent' => 'Clicked "I agree — sign this document"',
        ], recipient: $recipient, request: $request, actor: $recipient->email);

        // The link is spent. Reusing it after signing serves no purpose and
        // only widens the window in which a leaked URL matters.
        $recipient->forceFill([
            'access_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'token_expires_at' => Carbon::now('UTC'),
        ])->save();

        $sealing = false;
        if ($envelope->fresh()->allSignersComplete()) {
            $envelope->forceFill([
                'status' => Envelope::STATUS_COMPLETED,
                'completed_at' => Carbon::now('UTC'),
            ])->save();

            $this->audit->record($envelope, AuditEvent::ENVELOPE_COMPLETED, [
                'signers' => $envelope->recipients()->count(),
            ], request: $request, actor: 'system');

            SealEnvelope::dispatch($envelope->id);
            $sealing = true;
        } else {
            $envelope->forceFill(['status' => Envelope::STATUS_IN_PROGRESS])->save();
        }

        return response()->json([
            'message' => 'Signed.',
            'envelope_complete' => $sealing,
        ]);
    }

    public function decline(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->requireVerified($request, $uuid);
        $this->requireSignable($recipient);

        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $recipient->forceFill([
            'status' => Recipient::STATUS_DECLINED,
            'declined_at' => Carbon::now('UTC'),
            'decline_reason' => $reason,
        ])->save();

        $recipient->envelope->forceFill(['status' => Envelope::STATUS_DECLINED])->save();

        $this->audit->record($recipient->envelope, AuditEvent::RECIPIENT_DECLINED, [
            'reason' => $reason,
        ], recipient: $recipient, request: $request, actor: $recipient->email);

        return response()->json(['message' => 'Declined.']);
    }

    /** Streams the document for pdf.js. Requires a verified passcode. */
    public function document(Request $request, string $uuid): StreamedResponse
    {
        $recipient = $this->requireVerified($request, $uuid);

        $this->audit->record(
            $recipient->envelope,
            AuditEvent::RECIPIENT_VIEWED_DOCUMENT,
            ['filename' => $recipient->envelope->document->filename],
            recipient: $recipient,
            request: $request,
            actor: $recipient->email
        );

        return Storage::disk(config('signing.storage_disk'))->download(
            $recipient->envelope->document->storage_key,
            $recipient->envelope->document->filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    private function resolve(Request $request, string $uuid): Recipient
    {
        // Throttled per IP: the token is 256 bits, so guessing is hopeless, but
        // an unthrottled lookup endpoint is still free reconnaissance.
        $key = 'signer-token:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 60)) {
            abort(429, 'Too many requests.');
        }

        $token = (string) $request->query('t', $request->input('t', ''));
        $recipient = $this->tokens->resolve($uuid, $token);

        if (! $recipient) {
            RateLimiter::hit($key, 300);
            abort(404, 'This signing link is not valid.');
        }

        // 410 Gone, not 404: the distinction between "never existed" and
        // "expired" is genuinely useful to an honest signer looking at a stale
        // email, and reveals nothing to anyone who did not already hold a
        // valid token.
        abort_if($recipient->tokenHasExpired(), 410, 'This signing link has expired.');

        $envelope = $recipient->envelope;
        abort_if($envelope->isExpired(), 410, 'This envelope has expired.');
        abort_if(
            in_array($envelope->status, [
                Envelope::STATUS_VOIDED, Envelope::STATUS_DECLINED,
            ], true),
            410,
            'This envelope is no longer available for signing.'
        );

        return $recipient;
    }

    private function requireVerified(Request $request, string $uuid): Recipient
    {
        $recipient = $this->resolve($request, $uuid);

        abort_unless(
            $recipient->otp_verified,
            403,
            'Verify the code sent to your email address first.'
        );

        return $recipient;
    }

    private function requireConsent(Recipient $recipient): void
    {
        abort_unless(
            $recipient->consent !== null,
            403,
            'You must consent to using electronic records before signing.'
        );
    }

    private function requireSignable(Recipient $recipient): void
    {
        abort_if($recipient->hasSigned(), 422, 'You have already signed this document.');
        abort_unless($this->isTheirTurn($recipient), 403, 'It is not your turn to sign yet.');
    }

    /** Routing order: earlier signers must finish before later ones start. */
    private function isTheirTurn(Recipient $recipient): bool
    {
        return ! Recipient::where('envelope_id', $recipient->envelope_id)
            ->where('role', Recipient::ROLE_SIGNER)
            ->where('routing_order', '<', $recipient->routing_order)
            ->whereNull('signed_at')
            ->exists();
    }

    /** Accepts a data: URL or bare base64, and enforces the size limit. */
    private function decodeImage(string $input): string
    {
        if (str_starts_with($input, 'data:')) {
            $comma = strpos($input, ',');
            abort_if($comma === false, 422, 'Malformed image data.');
            $input = substr($input, $comma + 1);
        }

        $raw = base64_decode($input, true);
        abort_if($raw === false, 422, 'Malformed image data.');

        abort_if(
            strlen($raw) > (int) config('signing.upload.max_bytes'),
            422,
            'That image is too large.'
        );

        return $raw;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $shown = mb_substr($local, 0, 1);
        $masked = $shown . str_repeat('*', max(mb_strlen($local) - 1, 1));

        return $domain === '' ? $masked : "{$masked}@{$domain}";
    }
}
