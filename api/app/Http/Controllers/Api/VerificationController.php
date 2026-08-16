<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SignServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Public signature verification.
 *
 * Deliberately open and unauthenticated: the point of a verifiable signature is
 * that anybody holding the file can check it without needing an account here,
 * or needing this service to still exist. Uploaded bytes are inspected in
 * memory and never stored.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly SignServiceClient $signService)
    {
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $contents = file_get_contents($request->file('file')->getRealPath());

        if (! str_starts_with($contents, '%PDF-')) {
            throw ValidationException::withMessages(['file' => 'That file is not a PDF.']);
        }

        try {
            $report = $this->signService->verify($contents);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'file' => 'That PDF could not be read.',
            ]);
        }

        $signatures = $report['signatures'] ?? [];
        $intact = $signatures !== [] && collect($signatures)->every(fn ($s) => ($s['intact'] ?? false));
        $trusted = $signatures !== [] && collect($signatures)->every(fn ($s) => ($s['trusted'] ?? false));

        return response()->json([
            'signed' => $signatures !== [],
            // Reported separately on purpose. "The bytes have not changed" and
            // "the signer is who they claim" fail for entirely different
            // reasons, and collapsing them into one verdict hides which.
            'intact' => $intact,
            'trusted' => $trusted,
            'summary' => match (true) {
                $signatures === [] => 'This document carries no digital signature.',
                ! $intact => 'This document has been altered since it was signed.',
                ! $trusted => 'The signature is intact, but the signing certificate is not trusted by this server.',
                default => 'Signed, unaltered, and the signing certificate is trusted.',
            },
            'report' => $report,
        ]);
    }
}
