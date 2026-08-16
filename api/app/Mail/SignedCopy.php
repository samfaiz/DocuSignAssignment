<?php

namespace App\Mail;

use App\Models\Envelope;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SignedCopy extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Deliberately carries no PDF bytes.
     *
     * A queued mailable is serialised to JSON before it reaches the worker, and
     * binary PDF content is not valid UTF-8 — passing it as a property makes the
     * whole job fail to encode, so the signed copy silently never goes out. The
     * envelope alone is enough; the worker loads the sealed file from storage
     * when it builds the message.
     */
    public function __construct(
        public Envelope $signingEnvelope,
        public string $filename,
    ) {
    }

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: "Signed: {$this->signingEnvelope->subject}");
    }

    public function content(): Content
    {
        $sealed = $this->signingEnvelope->sealedDocument;

        return new Content(
            markdown: 'mail.signed-copy',
            with: [
                'padesLevel' => $sealed?->pades_level,
                'sha256' => $sealed?->sha256_sealed,
                'sealedAt' => $sealed?->sealed_at,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $sealed = $this->signingEnvelope->sealedDocument;

        if (! $sealed) {
            return [];
        }

        return [
            Attachment::fromData(
                fn () => Storage::disk(config('signing.storage_disk'))->get($sealed->storage_key),
                $this->filename
            )->withMime('application/pdf'),
        ];
    }
}
