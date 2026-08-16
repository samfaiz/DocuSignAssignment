<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Envelope extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'document_id', 'sender_id', 'subject', 'message', 'status',
        'sent_at', 'completed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** HasUuids would otherwise try to use `uuid` as the primary key. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class)->orderBy('routing_order')->orderBy('id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SignatureField::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class)->orderBy('seq');
    }

    public function sealedDocument(): HasOne
    {
        return $this->hasOne(SealedDocument::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED, self::STATUS_DECLINED,
            self::STATUS_VOIDED, self::STATUS_EXPIRED,
        ], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** True once every signer has signed. Viewers do not gate completion. */
    public function allSignersComplete(): bool
    {
        return ! $this->recipients()
            ->where('role', Recipient::ROLE_SIGNER)
            ->whereNull('signed_at')
            ->exists();
    }
}
