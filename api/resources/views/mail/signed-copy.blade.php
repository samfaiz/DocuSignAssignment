<x-mail::message>
# Signing complete

*{{ $signingEnvelope->subject }}* has been signed by everyone. Your copy is
attached.

The attached PDF carries a cryptographic signature, so any later change to it
is detectable. The final pages are a certificate of completion recording who
signed, how they were identified, and when.

@if ($padesLevel)
**Signature standard:** {{ $padesLevel }}
@endif
@if ($sealedAt)
**Sealed at:** {{ $sealedAt->format('j F Y, H:i') }} UTC
@endif
@if ($sha256)
**SHA-256 of the signed file:**
`{{ $sha256 }}`
@endif

Keep this file. To check it at any point, open it in Adobe Acrobat Reader and
look at the signature panel, or upload it to the verification page in the
portal.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
