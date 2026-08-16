<?php

namespace App\Mail;

use App\Models\Recipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class SigningOtp extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Recipient $recipient,
        public string $code,
    ) {
    }

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(subject: "Your signing verification code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.signing-otp',
            with: [
                'minutes' => (int) config('signing.otp.ttl_minutes'),
                'documentSubject' => $this->recipient->envelope->subject,
            ],
        );
    }
}
