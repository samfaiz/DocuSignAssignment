<?php

namespace App\Jobs;

use App\Mail\SignedCopy;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SealedDocument;
use App\Models\SignatureField;
use App\Services\AuditLogger;
use App\Services\SignServiceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Composites the signatures, appends the certificate of completion and applies
 * the PAdES seal.
 *
 * Queued rather than inline because sealing makes a network round trip to a
 * timestamp authority and fetches revocation data. That takes seconds and can
 * fail for reasons that have nothing to do with the signer — so it retries with
 * backoff instead of turning a transient TSA outage into a failed signature.
 */
class SealEnvelope implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    /** Widening gaps: a TSA outage is usually minutes, not seconds. */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public int $envelopeId)
    {
    }

    /** One seal per envelope, even if the job is dispatched twice. */
    public function uniqueId(): string
    {
        return 'seal-envelope-' . $this->envelopeId;
    }

    public function handle(SignServiceClient $signService, AuditLogger $audit): void
    {
        $envelope = Envelope::with([
            'document', 'sender', 'recipients.consent',
            'fields.value.asset', 'fields.recipient', 'auditEvents',
        ])->findOrFail($this->envelopeId);

        if ($envelope->sealedDocument) {
            Log::info("Envelope {$envelope->uuid} is already sealed; skipping.");

            return;
        }

        $original = Storage::disk('s3')->get($envelope->document->storage_key);

        $result = $signService->finalize(
            pdf: $original,
            placements: $this->placements($envelope),
            certificate: $this->certificatePayload($envelope, $audit),
            reason: "Signed via SignDesk — envelope {$envelope->uuid}",
        );

        $sealedPdf = base64_decode($result['pdf_b64']);
        $key = sprintf('sealed/%s/%s.pdf', $envelope->uuid, Str::uuid());
        Storage::disk('s3')->put($key, $sealedPdf);

        SealedDocument::create([
            'envelope_id' => $envelope->id,
            'storage_key' => $key,
            'sha256_stamped' => $result['sha256_stamped'],
            'sha256_sealed' => $result['sha256_sealed'],
            // What was achieved, not what was asked for. If the timestamp
            // authority was unreachable the service degrades, and the record
            // and the certificate both have to say so.
            'pades_level' => $result['pades_level'],
            'tsa_url' => $result['tsa_url'] ?? null,
            'certificate_subject' => $result['certificate_subject'] ?? null,
            'certificate_serial' => $result['certificate_serial'] ?? null,
            'page_count' => $result['page_count'],
            'warnings' => $result['warnings'] ?? [],
            'sealed_at' => Carbon::now('UTC'),
        ]);

        $audit->record($envelope, AuditEvent::ENVELOPE_SEALED, [
            'pades_level' => $result['pades_level'],
            'sha256_sealed' => $result['sha256_sealed'],
            'tsa_url' => $result['tsa_url'] ?? null,
            'warnings' => $result['warnings'] ?? [],
        ], actor: 'system');

        if (! empty($result['warnings'])) {
            Log::warning("Envelope {$envelope->uuid} sealed with warnings", $result['warnings']);
        }

        // The mailable loads the sealed PDF from storage itself. Handing it the
        // bytes here would put binary into a queued job payload, which cannot be
        // JSON-encoded — the job then fails to serialise and no copy is sent.
        $filename = 'signed-' . $envelope->document->filename;
        foreach ($this->distributionList($envelope) as $address) {
            Mail::to($address)->queue(new SignedCopy($envelope->fresh(), $filename));
        }
    }

    /**
     * Turn stored field values into placements the sealing service understands.
     *
     * Coordinates come from the database, never from the finishing request, so
     * a signer cannot move their own signature somewhere else in the document
     * on the way out.
     */
    private function placements(Envelope $envelope): array
    {
        $placements = [];

        foreach ($envelope->fields as $field) {
            $value = $field->value;
            if (! $value) {
                continue;
            }

            $placement = [
                'page' => $field->page,
                'x' => (float) $field->x,
                'y' => (float) $field->y,
                'w' => (float) $field->w,
                'h' => (float) $field->h,
            ];

            if ($value->asset) {
                $png = Storage::disk('s3')->get($value->asset->storage_key);
                $placement['image_b64'] = base64_encode($png);
            } elseif ($value->text_value !== null) {
                $placement['text'] = $value->text_value;
                $placement['font_size'] = 10.0;
            } else {
                continue;
            }

            $placements[] = $placement;
        }

        return $placements;
    }

    /** The evidence package, shaped for the certificate of completion. */
    private function certificatePayload(Envelope $envelope, AuditLogger $audit): array
    {
        return [
            'envelope' => [
                'id' => $envelope->uuid,
                'subject' => $envelope->subject,
                'status' => $envelope->status,
                'created_at' => $envelope->created_at?->format('Y-m-d H:i:s'),
                'completed_at' => $envelope->completed_at?->format('Y-m-d H:i:s'),
                'sender' => "{$envelope->sender->name} <{$envelope->sender->email}>",
            ],
            'document' => [
                'filename' => $envelope->document->filename,
                'page_count' => $envelope->document->page_count,
                'sha256_original' => $envelope->document->sha256_original,
            ],
            'recipients' => $envelope->recipients->map(fn (Recipient $r) => [
                'name' => $r->name,
                'email' => $r->email,
                // The method, not just the fact. "Verified" on its own is not
                // evidence of anything.
                'auth_method' => $r->auth_method ?? 'Email link',
                'signed_at' => $r->signed_at?->format('Y-m-d H:i:s') ?? '—',
                'ip' => $r->last_ip ?? '—',
                'consent_accepted_at' => $r->consent?->accepted_at?->format('Y-m-d H:i:s'),
                'consent_version' => $r->consent?->disclosure_version,
                'consent_ip' => $r->consent?->ip,
            ])->all(),
            'events' => $audit->projectForCertificate($envelope),
        ];
    }

    /** Every party gets the signed copy: all recipients plus the sender. */
    private function distributionList(Envelope $envelope): array
    {
        return $envelope->recipients
            ->pluck('email')
            ->push($envelope->sender->email)
            ->unique()
            ->values()
            ->all();
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Sealing envelope {$this->envelopeId} failed permanently", [
            'error' => $e->getMessage(),
        ]);
    }
}
