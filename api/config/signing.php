<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sealing service
    |--------------------------------------------------------------------------
    |
    | The PAdES engine runs as a separate Python service because no free PHP
    | library reaches PAdES B-LT/B-LTA: FPDI's open-source parser cannot read
    | PDF 1.5+ cross-reference streams, TCPDF's setSignature() emits only a
    | basic adbe.pkcs7.detached signature with no DSS or document-timestamp
    | chain, and SetaPDF-Signer is commercially licensed. See /docs.
    |
    */
    'service' => [
        'url' => env('SIGN_SERVICE_URL', 'http://127.0.0.1:8001'),
        'secret' => env('SIGN_SERVICE_SECRET'),
        'timeout' => (int) env('SIGN_SERVICE_TIMEOUT', 180),
    ],

    /*
    | Requested PAdES level. The service degrades honestly if the timestamp
    | authority is unreachable and reports what it actually achieved, which is
    | what gets stored and printed on the certificate of completion.
    */
    'pades_level' => env('SIGN_PADES_LEVEL', 'b-lta'),

    /*
    |--------------------------------------------------------------------------
    | Signing links
    |--------------------------------------------------------------------------
    */
    'token' => [
        'bytes' => 32,                                    // 256 bits of entropy
        'ttl_days' => (int) env('SIGN_TOKEN_TTL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time passcode
    |--------------------------------------------------------------------------
    |
    | Possession of the emailed link only proves someone can read that inbox —
    | including anyone it was forwarded to. The OTP is the step that ties the
    | ceremony to the intended person.
    |
    */
    'otp' => [
        'length' => 6,
        'ttl_minutes' => (int) env('SIGN_OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('SIGN_OTP_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('SIGN_OTP_LOCKOUT_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploaded signature images
    |--------------------------------------------------------------------------
    */
    'upload' => [
        'max_bytes' => 2 * 1024 * 1024,
        // Checked against the file's magic bytes, never its extension or the
        // Content-Type the browser claims.
        'allowed_mimes' => ['image/png', 'image/jpeg'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typed signature fonts
    |--------------------------------------------------------------------------
    |
    | All libre (SIL OFL / Apache 2.0) so they ship with the product rather than
    | being fetched from a font CDN during the signing ceremony. Rendering
    | happens server-side, so the artefact embedded in the sealed PDF does not
    | depend on the signer's installed fonts.
    |
    */
    'fonts' => [
        'great-vibes' => 'Great Vibes',
        'dancing-script' => 'Dancing Script',
        'homemade-apple' => 'Homemade Apple',
        'caveat' => 'Caveat',
        'sacramento' => 'Sacramento',
    ],

    /*
    |--------------------------------------------------------------------------
    | Electronic records disclosure
    |--------------------------------------------------------------------------
    |
    | The US ESIGN Act requires affirmative consent to transact electronically.
    | What matters in a dispute is which text was on screen, so the version and
    | a hash of the text are stored with every consent.
    |
    */
    'disclosure' => [
        'version' => env('ESIGN_DISCLOSURE_VERSION', 'esign-disclosure-v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Where documents and signature images are stored
    |--------------------------------------------------------------------------
    |
    | S3 (or MinIO) is the right answer at any real scale: versioning, lifecycle
    | rules, server-side encryption and pre-signed URLs come for free. But a
    | single-server deployment without an object store is a perfectly reasonable
    | place to start, and 'local' works there with no other changes.
    |
    | Whatever is chosen, files land outside the web root and are only ever
    | served through the application, never by path.
    |
    */
    'storage_disk' => env('SIGN_STORAGE_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Signed URL lifetime for document downloads
    |--------------------------------------------------------------------------
    */
    'download_url_ttl_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Evidence retention
    |--------------------------------------------------------------------------
    |
    | How long the more sensitive material captured during a ceremony is kept
    | in live storage. Photographs are biometric data; Illinois BIPA requires a
    | published destruction schedule outright, and GDPR and India's DPDP Act
    | both expect storage limitation rather than indefinite retention.
    |
    | Only the sensitive artefact is removed. The decision itself — that a photo
    | was requested, and whether the signer agreed — is kept forever, because
    | that is the part with evidential value and it holds no personal data.
    |
    | This reaches live storage only. A sealed document already emailed to the
    | parties carries its own copy and is beyond recall by design: it is
    | tamper-evident, so nothing can be removed from it after the fact.
    |
    | Set either to 0 to retain indefinitely.
    |
    */
    'retention' => [
        'photo_days' => (int) env('SIGN_PHOTO_RETENTION_DAYS', 90),
        'location_days' => (int) env('SIGN_LOCATION_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reviewer demo mode
    |--------------------------------------------------------------------------
    |
    | Lets someone evaluating this project provision a signing link and read the
    | one-time passcode back over the API, so they never have to open a terminal
    | or dig through a mail catcher.
    |
    | That second capability hands out a live authentication factor, so it is
    | genuinely dangerous: with it on, anyone who can reach the API can complete
    | somebody else's signing ceremony. It defaults to the local environment
    | only, the routes refuse to register anywhere else, and the passcode is
    | cached in plaintext exclusively while this is enabled.
    |
    */
    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', env('APP_ENV') === 'local'),
        'sample_pdf' => resource_path('demo/consulting-agreement.pdf'),

        /*
        | A throwaway account for reviewers, deliberately separate from any real
        | administrator. The demo page publishes these credentials, so they must
        | never be someone's actual login — on a public deployment that would
        | hand every visitor read access to every document in the system.
        |
        | Recreated with this password whenever a demo envelope is provisioned,
        | so what the page displays is always what actually works.
        */
        'admin_email' => env('DEMO_ADMIN_EMAIL', 'demo@signdesk.test'),
        'admin_password' => env('DEMO_ADMIN_PASSWORD', 'demo-signdesk-2026'),
    ],
];
