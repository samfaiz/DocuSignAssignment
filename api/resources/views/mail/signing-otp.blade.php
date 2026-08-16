<x-mail::message>
# Your verification code

Use this code to confirm it is you before signing *{{ $documentSubject }}*:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

This code expires in {{ $minutes }} minutes and can only be used once.

If you were not expecting this, someone may have your signing link — ignore
this email and the code will simply expire. Nothing is signed without it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
