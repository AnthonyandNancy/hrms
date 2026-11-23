<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->defineExceptionFixtureRoutes();
    }

    public function test_missing_route_returns_stable_not_found_envelope(): void
    {
        $response = $this->getJson('/v1/no-such-endpoint');

        $response->assertNotFound()
            ->assertJsonStructure(['code', 'message', 'data', 'request_id'])
            ->assertJsonPath('code', 'NOT_FOUND')
            ->assertJsonPath('message', 'The requested resource was not found.')
            ->assertJsonPath('data', []);

        $this->assertNoInternals($response->getContent());
    }

    public function test_validation_failure_returns_validation_envelope(): void
    {
        $response = $this->postJson('/v1/test-validation', []);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure(['data' => ['fields']]);

        $this->assertNoInternals($response->getContent());
    }

    public function test_method_not_allowed_returns_envelope(): void
    {
        $response = $this->deleteJson('/v1/meta');

        $response->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED')
            ->assertJsonStructure(['code', 'message', 'data', 'request_id']);

        $this->assertNoInternals($response->getContent());
    }

    public function test_throttled_request_returns_rate_limited_envelope(): void
    {
        $response = $this->getJson('/v1/test-throttle');

        $response->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED')
            ->assertJsonStructure(['code', 'message', 'data', 'request_id']);

        $this->assertNoInternals($response->getContent());
    }

    public function test_debug_mode_does_not_leak_internals(): void
    {
        config()->set('app.debug', true);

        $response = $this->getJson('/v1/test-boom');

        $response->assertStatus(500)
            ->assertJsonPath('code', 'INTERNAL')
            ->assertJsonPath('message', 'An unexpected error occurred.');

        $this->assertNoInternals($response->getContent());
    }

    private function assertNoInternals(string|false $body): void
    {
        $this->assertIsString($body);
        foreach (['Exception', 'Stack trace', '.php', 'SQLSTATE', 'vendor/', 'Tests'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "Response leaked internal detail: {$needle}");
        }
    }
}
