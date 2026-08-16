<?php

namespace App\Mail;

use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class SigningInvitation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Envelope $signingEnvelope,
        public Recipient $recipient,
        public string $signingUrl,
    ) {
    }

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(
            subject: "Please sign: {$this->signingEnvelope->subject}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.signing-invitation',
            with: [
                'senderName' => $this->signingEnvelope->sender->name,
                'documentName' => $this->signingEnvelope->document->filename,
                'expiresAt' => $this->recipient->token_expires_at,
            ],
        );
    }
}
