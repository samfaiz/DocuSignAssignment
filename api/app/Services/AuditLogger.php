<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Append-only, hash-chained evidence log.
 *
 * Every event's hash covers the previous event's hash, so the log forms a chain
 * per envelope. Editing, deleting or reordering any event changes its hash and
 * therefore breaks every hash after it, which `verifyChain()` detects.
 *
 * This makes tampering *detectable*, not impossible: anyone with write access
 * could recompute the chain from the edit onward. What closes that gap is that
 * the final state ends up inside a PAdES-signed PDF carrying an RFC 3161
 * timestamp from an authority we do not control — so a rewritten chain can no
 * longer be made to match the sealed document.
 */
class AuditLogger
{
    public function record(
        Envelope $envelope,
        string $type,
        array $payload = [],
        ?Recipient $recipient = null,
        ?Request $request = null,
        ?string $actor = null,
    ): AuditEvent {
        return DB::transaction(function () use ($envelope, $type, $payload, $recipient, $request, $actor) {
            // Lock the envelope row for the duration. Two concurrent events —
            // say a signer finishing while an admin voids — would otherwise
            // race for the same sequence number and produce a forked chain.
            DB::table('envelopes')->where('id', $envelope->id)->lockForUpdate()->first();

            $previous = AuditEvent::where('envelope_id', $envelope->id)
                ->orderByDesc('seq')
                ->first();

            $event = new AuditEvent([
                'envelope_id' => $envelope->id,
                'recipient_id' => $recipient?->id,
                'seq' => ($previous?->seq ?? 0) + 1,
                'type' => $type,
                'actor' => $actor ?? $recipient?->email ?? auth()->user()?->email,
                'payload' => $payload,
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'occurred_at' => Carbon::now('UTC'),
                'prev_hash' => $previous?->hash ?? AuditEvent::GENESIS_HASH,
            ]);

            // Computed from the in-memory values, before any database round
            // trip could round the microseconds off the timestamp.
            $event->hash = $event->computeHash();
            $event->save();

            return $event;
        });
    }

    /**
     * Walk an envelope's chain and recompute every hash.
     *
     * @return array{valid: bool, count: int, broken_at: int|null, reason: string|null}
     */
    public function verifyChain(Envelope $envelope): array
    {
        $events = AuditEvent::where('envelope_id', $envelope->id)
            ->orderBy('seq')
            ->get();

        $expectedPrev = AuditEvent::GENESIS_HASH;
        $expectedSeq = 1;

        foreach ($events as $event) {
            if ($event->seq !== $expectedSeq) {
                return $this->broken($events->count(), $event->seq,
                    "sequence gap: expected {$expectedSeq}, found {$event->seq}");
            }

            if ($event->prev_hash !== $expectedPrev) {
                return $this->broken($events->count(), $event->seq,
                    'prev_hash does not match the preceding event');
            }

            if (! hash_equals($event->hash, $event->computeHash())) {
                return $this->broken($events->count(), $event->seq,
                    'stored hash does not match the recomputed hash — the row was altered');
            }

            $expectedPrev = $event->hash;
            $expectedSeq++;
        }

        return [
            'valid' => true,
            'count' => $events->count(),
            'broken_at' => null,
            'reason' => null,
        ];
    }

    private function broken(int $count, int $seq, string $reason): array
    {
        return ['valid' => false, 'count' => $count, 'broken_at' => $seq, 'reason' => $reason];
    }

    /** The audit trail shaped for the certificate of completion. */
    public function projectForCertificate(Envelope $envelope): array
    {
        return $envelope->auditEvents->map(fn (AuditEvent $e) => [
            'seq' => $e->seq,
            'type' => $e->type,
            'actor' => $e->actor ?? '—',
            'occurred_at' => $e->occurred_at->format('Y-m-d H:i:s'),
            'ip' => $e->ip ?? '—',
            'hash' => $e->hash,
        ])->all();
    }
}
