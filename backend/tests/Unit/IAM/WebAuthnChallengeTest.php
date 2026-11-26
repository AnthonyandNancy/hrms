<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use App\Modules\IAM\Services\WebAuthnService;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class WebAuthnChallengeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_challenge_is_random_stored_once_and_expires(): void
    {
        $service = app(WebAuthnService::class);
        $first = $service->beginRegistration(10);
        $second = $service->beginRegistration(10);

        $this->assertNotSame($first['challenge'], $second['challenge']);
        $this->assertSame('webauthn.create', $first['public_key']['challenge_type'] ?? 'webauthn.create');
        $this->assertSame(32, strlen($service->base64UrlDecode($first['challenge'])));
    }

    public function test_client_data_with_wrong_challenge_or_origin_is_rejected(): void
    {
        $service = app(WebAuthnService::class);
        $options = $service->beginRegistration(10);
        $challenge = $options['challenge'];

        $badChallenge = $this->clientData('webauthn.create', 'd3JvbmctY2hhbGxlbmdl', 'https://app.example.test');
        $badOrigin = $this->clientData('webauthn.create', $challenge, 'https://evil.example.test');

        foreach ([$badChallenge, $badOrigin] as $clientData) {
            try {
                $service->verifyRegistrationClientData(10, $clientData);
                $this->fail('Invalid client data must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame('WEBAUTHN_INVALID', $exception->details()['reason']);
            }
        }
    }

    public function test_challenge_is_single_use(): void
    {
        $service = app(WebAuthnService::class);
        $options = $service->beginRegistration(10);
        $clientData = $this->clientData('webauthn.create', $options['challenge'], 'http://localhost');

        $service->verifyRegistrationClientData(10, $clientData);

        $this->expectException(ValidationException::class);
        $service->verifyRegistrationClientData(10, $clientData);
    }

    public function test_assertion_requires_authentication_type_and_challenge(): void
    {
        $service = app(WebAuthnService::class);
        $options = $service->beginAssertion(10);
        $clientData = $this->clientData('webauthn.get', $options['challenge'], 'http://localhost');

        $service->verifyAssertionClientData(10, $clientData);

        $this->expectException(ValidationException::class);
        $service->verifyAssertionClientData(10, $this->clientData('webauthn.create', $options['challenge'], 'http://localhost'));
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return $this->base64Url(json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
