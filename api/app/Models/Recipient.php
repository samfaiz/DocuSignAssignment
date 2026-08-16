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
    ];

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
        ];
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
