<x-mail::message>
# You have a document to sign

**{{ $senderName }}** has sent you *{{ $signingEnvelope->subject }}* to sign.

@if ($signingEnvelope->message)
> {{ $signingEnvelope->message }}
@endif

<x-mail::button :url="$signingUrl">
Review and sign
</x-mail::button>

**Document:** {{ $documentName }}

@if ($expiresAt)
This link expires on {{ $expiresAt->format('j F Y') }}.
@endif

This link is unique to you — please do not forward it. You will be asked to
enter a verification code sent to this email address before you can sign.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
