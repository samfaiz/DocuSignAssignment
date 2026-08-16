<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\SignServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly SignServiceClient $signService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $documents = Document::where('owner_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($documents);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $upload = $request->file('file');
        $contents = file_get_contents($upload->getRealPath());

        // The extension and the browser-supplied content type are both just
        // claims. What settles it is whether a real PDF parser can read the
        // bytes — which is what /inspect does.
        if (! str_starts_with($contents, '%PDF-')) {
            throw ValidationException::withMessages([
                'file' => 'That file is not a PDF.',
            ]);
        }

        try {
            $info = $this->signService->inspect($contents);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'file' => str_contains($e->getMessage(), 'password-protected')
                    ? 'Password-protected PDFs cannot be signed. Remove the password first.'
                    : 'That PDF could not be read.',
            ]);
        }

        // Random key, never the user's filename: an attacker-chosen name must
        // not be able to reach outside the prefix or collide with another
        // tenant's object.
        $key = sprintf(
            'documents/%s/%s.pdf',
            $request->user()->id,
            Str::uuid()
        );

        Storage::disk(config('signing.storage_disk'))->put($key, $contents);

        $document = Document::create([
            'owner_id' => $request->user()->id,
            'filename' => $upload->getClientOriginalName(),
            'storage_key' => $key,
            'sha256_original' => $info['sha256'],
            'page_count' => $info['page_count'],
            'size_bytes' => $info['size_bytes'],
        ]);

        return response()->json([
            'document' => $document,
            'first_page' => $info['first_page'] ?? null,
        ], 201);
    }

    /** Streams the original PDF so the SPA can render it with pdf.js. */
    public function download(Request $request, Document $document): StreamedResponse
    {
        abort_unless($document->owner_id === $request->user()->id, 403);

        return Storage::disk(config('signing.storage_disk'))->download(
            $document->storage_key,
            $document->filename,
            ['Content-Type' => 'application/pdf']
        );
    }
}
