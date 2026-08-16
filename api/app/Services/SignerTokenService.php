<?php

namespace App\Services;

use App\Models\Recipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Signing links and one-time passcodes.
 *
 * Two distinct factors:
 *
 *   The link proves someone can read the recipient's inbox. That is not the
 *   same as being the recipient — mail gets forwarded, shared and breached.
 *
 *   The OTP, delivered to that same address at the moment of signing, narrows
 *   it to someone with live access at signing time. It is not strong identity
 *   proofing; where the law requires that (India's IT Act s.3A, eIDAS QES) the
 *   binding has to come from a licensed provider. What it does give is a
 *   defensible, recorded authentication step — and the method is written into
 *   the certificate of completion rather than being summarised as "verified".
 */
class SignerTokenService
{
    /**
     * Issue a signing token. Returns the plaintext exactly once — only its
     * SHA-256 is persisted, so a database dump yields no working links.
     */
    public function issue(Recipient $recipient): string
    {
        $token = bin2hex(random_bytes((int) config('signing.token.bytes')));

        $recipient->forceFill([
            'access_token_hash' => hash('sha256', $token),
            'token_expires_at' => Carbon::now('UTC')
                ->addDays((int) config('signing.token.ttl_days')),
        ])->save();

        return $token;
    }

    /**
     * Look up a recipient by plaintext token.
     *
     * The lookup is by hash, so the database index does the work and there is
     * no row-by-row comparison to time. hash_equals still guards the final
     * check against any timing signal in the comparison itself.
     */
    public function resolve(string $envelopeUuid, string $token): ?Recipient
    {
        if ($token === '') {
            return null;
        }

        $recipient = Recipient::query()
            ->where('access_token_hash', hash('sha256', $token))
            ->whereHas('envelope', fn ($q) => $q->where('uuid', $envelopeUuid))
            ->first();

        if (! $recipient) {
            return null;
        }

        return hash_equals($recipient->access_token_hash, hash('sha256', $token))
            ? $recipient
            : null;
    }

    /** Generate, hash and store a one-time passcode. Returns the plaintext. */
    public function issueOtp(Recipient $recipient): string
    {
        $length = (int) config('signing.otp.length');
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $recipient->forceFill([
            // Hashed with the password hasher, not sha256: a 6-digit code has
            // only a million possibilities, so a fast hash would fall to an
            // offline sweep in moments if the database leaked.
            'otp_hash' => bcrypt($code),
            'otp_expires_at' => Carbon::now('UTC')
                ->addMinutes((int) config('signing.otp.ttl_minutes')),
            'otp_attempts' => 0,
            'otp_verified' => false,
        ])->save();

        // Demo mode only: keep the plaintext just long enough for a reviewer to
        // read it back without opening a mail catcher. This is the one place in
        // the system where a live authentication factor is recoverable, which is
        // why it is gated rather than merely undocumented.
        if (config('signing.demo.enabled')) {
            Cache::put(
                self::demoOtpKey($recipient),
                $code,
                Carbon::now('UTC')->addMinutes((int) config('signing.otp.ttl_minutes'))
            );
        }

        return $code;
    }

    /**
     * @return array{ok: bool, reason: string|null}
     */
    public function verifyOtp(Recipient $recipient, string $code): array
    {
        if ($recipient->isOtpLocked()) {
            return ['ok' => false, 'reason' => 'locked'];
        }

        if (! $recipient->otp_hash || ! $recipient->otp_expires_at) {
            return ['ok' => false, 'reason' => 'not_issued'];
        }

        if ($recipient->otp_expires_at->isPast()) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        if (! password_verify($code, $recipient->otp_hash)) {
            $attempts = $recipient->otp_attempts + 1;
            $max = (int) config('signing.otp.max_attempts');

            $recipient->forceFill([
                'otp_attempts' => $attempts,
                // Lock out rather than merely counting: without this a six-digit
                // code is brute-forceable in well under a million requests.
                'otp_locked_until' => $attempts >= $max
                    ? Carbon::now('UTC')->addMinutes((int) config('signing.otp.lockout_minutes'))
                    : null,
            ])->save();

            return ['ok' => false, 'reason' => $attempts >= $max ? 'locked' : 'invalid'];
        }

        $recipient->forceFill([
            'otp_verified' => true,
            'otp_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
            'otp_locked_until' => null,
            'auth_method' => 'Email link + email OTP',
        ])->save();

        return ['ok' => true, 'reason' => null];
    }

    /** Cache key holding the plaintext passcode. Demo mode only. */
    public static function demoOtpKey(Recipient $recipient): string
    {
        return "demo:otp:{$recipient->id}";
    }

    /** Signer-facing URL for this recipient. */
    public function signingUrl(Recipient $recipient, string $token): string
    {
        return rtrim((string) env('SPA_URL', config('app.url')), '/')
            . '/sign/' . $recipient->envelope->uuid
            . '?t=' . $token;
    }
}
