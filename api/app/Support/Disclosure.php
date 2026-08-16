<?php

namespace App\Support;

/**
 * The electronic-records disclosure shown on the consent screen.
 *
 * Kept in code and versioned rather than edited in a database row, because the
 * evidential question is never "did they consent" but "to what text". Changing
 * the wording means adding a new version; existing consents keep pointing at
 * the text that was actually on screen when they were given, and the hash lets
 * that be proved rather than asserted.
 */
class Disclosure
{
    private const TEXTS = [
        'esign-disclosure-v1' => <<<'TEXT'
        CONSENT TO USE ELECTRONIC RECORDS AND SIGNATURES

        1. Electronic delivery. You agree to receive this document, and any
           related notices and disclosures, electronically rather than on paper.

        2. Electronic signature. You agree that your electronic signature on
           this document has the same legal effect as a handwritten signature,
           and that you are signing with the intent to be bound by the document.

        3. Hardware and software. To view and retain these records you need a
           current web browser, an internet connection, a valid email address,
           and the ability to view and save PDF files.

        4. Withdrawing consent. You may decline to sign electronically at any
           time before you complete signing, by using the "Decline" option. If
           you decline, the sender will be notified and no signature is applied.

        5. Retaining a copy. When signing is complete you will receive a copy of
           the signed document by email, and can download it from this portal.
           The signed file carries a cryptographic signature and a certificate
           of completion recording how you were identified and when you signed.

        6. Requesting paper copies. You may ask the sender for a paper copy of
           any record. The sender may charge for this.

        7. Updating your details. Tell the sender directly if your email address
           changes.

        By continuing you confirm that you have read and agree to the above, and
        that you are able to access and retain electronic records.
        TEXT,
    ];

    public static function current(): array
    {
        $version = (string) config('signing.disclosure.version');

        return self::forVersion($version);
    }

    public static function forVersion(string $version): array
    {
        $text = self::TEXTS[$version] ?? null;

        if ($text === null) {
            throw new \InvalidArgumentException("Unknown disclosure version: {$version}");
        }

        // Normalised before hashing so re-indenting the heredoc in a later edit
        // does not silently change the hash of text that reads identically.
        $normalised = self::normalise($text);

        return [
            'version' => $version,
            'text' => $normalised,
            'sha256' => hash('sha256', $normalised),
        ];
    }

    private static function normalise(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $lines = array_map(static fn (string $line) => rtrim($line), $lines);

        return trim(implode("\n", $lines));
    }
}
