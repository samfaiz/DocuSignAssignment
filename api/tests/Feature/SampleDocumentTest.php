<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The bundled sample agreement.
 *
 * It exists so someone evaluating the product can try the sending flow without
 * first going to find a PDF — but it must not become a side door around the
 * checks a real upload goes through.
 */
class SampleDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/documents/sample')->assertUnauthorized();
    }

    public function test_the_bundled_file_exists(): void
    {
        $path = config('signing.demo.sample_pdf');

        $this->assertIsString($path);
        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF-', file_get_contents($path));
    }

    public function test_it_creates_a_document_owned_by_the_caller(): void
    {
        // Genuinely an integration test: the endpoint validates the PDF through
        // the Python service, exactly as a real upload does. Skipped rather than
        // failed when that service is not running, so the reason is obvious.
        $this->skipUnlessSignServiceIsRunning();

        Storage::fake(config('signing.storage_disk'));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/documents/sample')->assertCreated();

        $document = Document::firstOrFail();

        $this->assertSame($user->id, $document->owner_id);
        $this->assertSame('consulting-agreement.pdf', $document->filename);
        // Hashed and page-counted exactly like a real upload, not trusted blindly.
        $this->assertSame(64, strlen($document->sha256_original));
        $this->assertGreaterThan(0, $document->page_count);
        $this->assertSame($document->id, $response->json('document.id'));

        // Stored under a generated key, never a caller-supplied filename.
        $this->assertStringContainsString("documents/{$user->id}/", $document->storage_key);
        Storage::disk(config('signing.storage_disk'))->assertExists($document->storage_key);
    }

    private function skipUnlessSignServiceIsRunning(): void
    {
        try {
            $healthy = \Illuminate\Support\Facades\Http::timeout(3)
                ->get(rtrim((string) config('signing.service.url'), '/') . '/health')
                ->successful();
        } catch (\Throwable) {
            $healthy = false;
        }

        if (! $healthy) {
            $this->markTestSkipped(
                'The sealing service is not running at ' . config('signing.service.url')
            );
        }
    }
}
