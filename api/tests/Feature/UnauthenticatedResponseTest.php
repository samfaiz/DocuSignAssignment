<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How protected endpoints behave for callers with no credentials.
 *
 * Both branches matter. The SPA always asks for JSON and expects a 401 it can
 * act on. A browser, crawler or uptime probe opening an API URL directly asks
 * for HTML — and Laravel's default answer there is route('login'), which does
 * not exist in an API-plus-SPA application and turns a 401 into a 500.
 */
class UnauthenticatedResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_json_request_gets_401(): void
    {
        $this->getJson('/api/settings/mail')->assertUnauthorized();
        $this->getJson('/api/envelopes')->assertUnauthorized();
    }

    public function test_a_browser_request_gets_401_rather_than_a_server_error(): void
    {
        // No Accept: application/json — the branch that used to crash.
        $response = $this->get('/api/settings/mail');

        // Still 401 rather than a redirect: shouldRenderJsonWhen matches api/*,
        // so once redirectTo() stops throwing, the handler renders JSON. An API
        // answering every unauthenticated caller the same way is the behaviour
        // we want; the redirect target only exists to keep Laravel from
        // resolving a route that was never registered.
        $response->assertUnauthorized();
    }

    public function test_every_protected_route_avoids_the_500(): void
    {
        foreach ([
            '/api/me',
            '/api/documents',
            '/api/envelopes',
            '/api/settings/mail',
        ] as $path) {
            $this->assertNotSame(
                500,
                $this->get($path)->getStatusCode(),
                "{$path} returned a server error for an unauthenticated browser request"
            );
        }
    }
}
