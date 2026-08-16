<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Python sealing service.
 *
 * Requests are authenticated with an HMAC over the exact JSON body. The service
 * should have no public ingress; the HMAC is the second layer, so that reaching
 * the port is not the same as being able to use it.
 */
class SignServiceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
        private readonly int $timeout,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('signing.service.url'), '/'),
            (string) config('signing.service.secret'),
            (int) config('signing.service.timeout'),
        );
    }

    public function health(): array
    {
        return Http::timeout(10)->get("{$this->baseUrl}/health")->json() ?? [];
    }

    /** Render a typed name into a PNG using one of the bundled script fonts. */
    public function typedSignature(string $name, string $font, int $height = 160): array
    {
        return $this->post('/typed-signature', [
            'name' => $name,
            'font' => $font,
            'height' => $height,
        ]);
    }

    /** Decode, background-strip and re-encode an uploaded signature image. */
    public function sanitizeSignature(string $rawImage): array
    {
        return $this->post('/sanitize-signature', [
            'image_b64' => base64_encode($rawImage),
        ]);
    }

    /**
     * Composite the signatures, append the certificate of completion, and seal.
     *
     * One round trip so the intermediate document — which carries signature
     * images but no cryptographic protection yet — never touches disk or
     * crosses the network in that half-finished state.
     */
    public function finalize(
        string $pdf,
        array $placements,
        array $certificate,
        ?string $level = null,
        string $reason = 'Electronically signed via SignDesk',
    ): array {
        return $this->post('/finalize', [
            'pdf_b64' => base64_encode($pdf),
            'placements' => $placements,
            'certificate' => $certificate,
            'seal' => [
                'level' => $level ?? config('signing.pades_level'),
                'reason' => $reason,
            ],
        ]);
    }

    public function verify(string $pdf): array
    {
        return $this->post('/verify', ['pdf_b64' => base64_encode($pdf)]);
    }

    /**
     * Validate an uploaded PDF and read its page count and dimensions.
     *
     * Rejecting a bad upload here — where a real parser looks at it — is much
     * better than discovering the problem inside a queue job after the sender
     * has already been told the envelope went out.
     */
    public function inspect(string $pdf): array
    {
        return $this->post('/inspect', ['pdf_b64' => base64_encode($pdf)]);
    }

    private function post(string $path, array $payload): array
    {
        // The HMAC must cover the identical bytes the service receives, so the
        // body is encoded once here and sent raw — re-encoding it inside the
        // HTTP client could reorder keys or change escaping and break the digest.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = $this->request($body)->send('POST', "{$this->baseUrl}{$path}", [
            'body' => $body,
        ]);

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();
            throw new RuntimeException(
                "sign-service {$path} failed ({$response->status()}): " .
                (is_string($detail) ? $detail : json_encode($detail))
            );
        }

        return $response->json() ?? [];
    }

    private function request(string $body): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-SignDesk-Signature' => 'sha256=' . hash_hmac('sha256', $body, $this->secret),
            ]);
    }
}
