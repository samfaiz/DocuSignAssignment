<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One link in an envelope's evidence chain.
 *
 * Rows are written by App\Services\AuditLogger and never by hand: the hash of
 * each event covers the previous event's hash, so writing out of band would
 * break the chain. The database also refuses UPDATE and DELETE outright — the
 * guards below just fail earlier, with a clearer message.
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const ENVELOPE_CREATED = 'envelope.created';
    public const ENVELOPE_SENT = 'envelope.sent';
    public const ENVELOPE_COMPLETED = 'envelope.completed';
    public const ENVELOPE_VOIDED = 'envelope.voided';
    public const ENVELOPE_SEALED = 'envelope.sealed';

    public const RECIPIENT_EMAIL_SENT = 'recipient.email_sent';
    public const RECIPIENT_OPENED = 'recipient.opened';
    public const RECIPIENT_OTP_SENT = 'recipient.otp_sent';
    public const RECIPIENT_OTP_VERIFIED = 'recipient.otp_verified';
    public const RECIPIENT_OTP_FAILED = 'recipient.otp_failed';
    public const RECIPIENT_CONSENTED = 'recipient.consented';
    public const RECIPIENT_VIEWED_DOCUMENT = 'recipient.viewed_document';
    public const RECIPIENT_FIELD_COMPLETED = 'recipient.field_completed';
    public const RECIPIENT_SIGNED = 'recipient.signed';
    public const RECIPIENT_DECLINED = 'recipient.declined';

    /** Seeds the chain for an envelope's first event. */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    protected $fillable = [
        'envelope_id', 'recipient_id', 'seq', 'type', 'actor',
        'payload', 'ip', 'user_agent', 'occurred_at', 'prev_hash', 'hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Audit events are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit events are append-only and cannot be deleted.');
        });
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /**
     * The exact bytes this row's hash is computed over.
     *
     * Field order and separator are part of the format: change either and every
     * previously stored hash stops reproducing. The microsecond timestamp keeps
     * two events in the same second distinguishable.
     */
    public function canonicalPayload(): string
    {
        return implode('|', [
            $this->prev_hash,
            $this->seq,
            $this->type,
            $this->canonicalJson($this->payload ?? []),
            $this->occurred_at->format('Y-m-d\TH:i:s.uP'),
        ]);
    }

    /**
     * Serialise the payload so it survives a database round trip byte for byte.
     *
     * Postgres `jsonb` stores a parsed structure, not the text it was given,
     * and it does not preserve key order — it returns object keys sorted by
     * length then bytewise. So a payload written as {"to":…,"expires_at":…}
     * reads back as {"to":…,"expires_at":…} in a different order, re-encodes to
     * different bytes, and the recomputed hash no longer matches the stored one.
     *
     * Sorting keys recursively before hashing makes the encoding canonical, so
     * the hash depends on the payload's content rather than on the order it
     * happened to be written in. (Same idea as JSON Canonicalisation, RFC 8785.)
     */
    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->sortKeys($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($item) => $this->sortKeys($item), $value);

        // Lists keep their order — sequence is meaningful in an array.
        if (array_is_list($value)) {
            return $value;
        }

        ksort($value);

        return $value;
    }

    public function computeHash(): string
    {
        return hash('sha256', $this->canonicalPayload());
    }
}
