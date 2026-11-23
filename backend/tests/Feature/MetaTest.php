<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MetaTest extends TestCase
{
    public function test_meta_returns_version_environment_time_and_api_version(): void
    {
        $response = $this->getJson('/v1/meta');

        $response->assertOk()
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.environment', 'testing')
            ->assertJsonStructure([
                'code',
                'message',
                'data' => ['version', 'environment', 'server_time', 'supported_timezones', 'api_version'],
                'request_id',
            ]);
        $response->assertHeader('X-Request-Id');

        $this->assertContains('UTC', $response->json('data.supported_timezones'));
        $this->assertStringEndsWith('Z', $response->json('data.server_time'));
    }

    public function test_root_returns_api_meta_instead_of_unused_welcome_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('data.api_version', 'v1');
    }
}
