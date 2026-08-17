<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Recipient extends Model
{
    use HasFactory;

    public const ROLE_SIGNER = 'signer';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_APPROVER = 'approver';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'envelope_id', 'name', 'email', 'phone', 'role', 'routing_order',
        'status', 'access_token_hash', 'token_expires_at', 'auth_method',
        'otp_hash', 'otp_expires_at', 'otp_attempts', 'otp_locked_until',
        'otp_verified', 'viewed_at', 'signed_at', 'declined_at',
        'decline_reason', 'last_ip', 'last_user_agent',
        'location_consent', 'latitude', 'longitude',
        'location_accuracy_m', 'location_captured_at',
        'photo_consent', 'photo_storage_key', 'photo_sha256', 'photo_captured_at',
    ];

    /**
     * Mirrors the column default so a freshly created model reports the same
     * state as one read back from the database. Without it the attribute is
     * null in memory until something reloads the row, and "never asked" and
     * "unknown" become indistinguishable at exactly the moment they matter.
     */
    protected $attributes = [
        'location_consent' => 'not_asked',
        'photo_consent' => 'not_asked',
    ];

    public const LOCATION_NOT_ASKED = 'not_asked';
    public const LOCATION_GRANTED = 'granted';
    public const LOCATION_DENIED = 'denied';
    public const LOCATION_UNSUPPORTED = 'unsupported';
    public const LOCATION_FAILED = 'failed';

    /**
     * Token and OTP hashes must never reach an API response — a signer's own
     * payload would otherwise leak the credential that authenticates them.
     */
    protected $hidden = ['access_token_hash', 'otp_hash'];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'otp_locked_until' => 'datetime',
            'otp_verified' => 'boolean',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'location_captured_at' => 'datetime',
            'photo_captured_at' => 'datetime',
        ];
    }

    public function hasPhoto(): bool
    {
        return $this->photo_storage_key !== null;
    }

    /**
     * How the photograph should be described on the certificate.
     *
     * Never worded as verification. A photograph establishes that someone was
     * present and willing to be photographed; without a government ID, a face
     * match against it and liveness detection, it does not establish who.
     */
    public function photoSummary(): string
    {
        return match ($this->photo_consent) {
            self::LOCATION_GRANTED => $this->hasPhoto()
                ? 'Photograph captured at signing (not identity-verified)'
                : 'Agreed, but no photograph was stored',
            self::LOCATION_DENIED => 'Declined by signer',
            self::LOCATION_UNSUPPORTED => 'No camera available on signer device',
            self::LOCATION_FAILED => 'Attempted, camera could not be started',
            default => 'Not requested',
        };
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * How the location should be described on the certificate of completion.
     *
     * Always qualified as reported rather than verified: these coordinates come
     * from the signer's browser, not from anything the server observed.
     */
    public function locationSummary(): string
    {
        return match ($this->location_consent) {
            self::LOCATION_GRANTED => $this->hasLocation()
                ? sprintf(
                    '%.5f, %.5f (reported, +/-%s m)',
                    $this->latitude,
                    $this->longitude,
                    $this->location_accuracy_m ?? '?'
                )
                : 'Shared, but no coordinates recorded',
            self::LOCATION_DENIED => 'Declined by signer',
            self::LOCATION_UNSUPPORTED => 'Not available on signer device',
            self::LOCATION_FAILED => 'Attempted, device could not determine it',
            default => 'Not requested',
        };
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SignatureField::class);
    }

    public function signatureAssets(): HasMany
    {
        return $this->hasMany(SignatureAsset::class);
    }

    public function consent(): HasOne
    {
        return $this->hasOne(Consent::class)->latestOfMany();
    }

    public function hasSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function isOtpLocked(): bool
    {
        return $this->otp_locked_until !== null && $this->otp_locked_until->isFuture();
    }

    public function tokenHasExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}
