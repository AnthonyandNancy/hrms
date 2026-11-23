<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_valid_inbound_request_id_is_preserved(): void
    {
        $response = $this->getJson('/v1/meta', ['X-Request-Id' => 'client-req-12345678']);

        $response->assertOk();
        $response->assertHeader('X-Request-Id', 'client-req-12345678');
        $response->assertJsonPath('request_id', 'client-req-12345678');
    }

    public function test_invalid_inbound_request_id_is_replaced_with_ulid(): void
    {
        $response = $this->getJson('/v1/meta', ['X-Request-Id' => 'bad id']);

        $response->assertOk();
        $requestId = $response->headers->get('X-Request-Id');

        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $requestId);
        $response->assertJsonPath('request_id', $requestId);
    }

    public function test_response_header_is_present_without_inbound_id(): void
    {
        $response = $this->getJson('/v1/health');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $response->assertJsonPath('request_id', $response->headers->get('X-Request-Id'));
    }

    public function test_error_responses_also_carry_request_id(): void
    {
        $response = $this->getJson('/v1/does-not-exist', ['X-Request-Id' => 'error-req-12345678']);

        $response->assertNotFound();
        $response->assertHeader('X-Request-Id', 'error-req-12345678');
        $response->assertJsonPath('request_id', 'error-req-12345678');
    }
}
