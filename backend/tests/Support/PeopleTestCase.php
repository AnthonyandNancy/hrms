<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\Authorizer;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class PeopleTestCase extends TestCase
{
    use RefreshDatabase;

    protected FrozenClock $clock;

    protected AllowAllAuthorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->aliasMiddleware('capability', TestCapabilityMiddleware::class);
        $this->app['router']->aliasMiddleware('auth.session', TestSessionMiddleware::class);

        config()->set('hrms.default_timezone', 'Asia/Shanghai');
        config()->set('hrms.pii_hmac_key', 'test-pii-hmac-key');

        $this->clock = new FrozenClock('2026-11-23T01:00:00Z');
        $this->app->instance(Clock::class, $this->clock);

        $this->authorizer = new AllowAllAuthorizer;
        $this->app->instance(Authorizer::class, $this->authorizer);

        $this->actAs();
    }

    protected function actAs(int $tenantId = 1, int $userId = 10, ?int $employmentId = null): TestPrincipal
    {
        $principal = new TestPrincipal($tenantId, $userId, $employmentId);
        $this->app->instance(Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);

        if ($this->app->bound(\App\Modules\IAM\Auth\AuthenticationContext::class)) {
            $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);
        }

        return $principal;
    }

    protected function forgetPrincipal(): void
    {
        $this->app->forgetInstance(Principal::class);
        $this->app->forgetInstance(\App\Shared\Auth\AuthenticatedPrincipal::class);
    }

    protected function scenario(int $tenantId = 1): PeopleScenario
    {
        return new PeopleScenario($tenantId, $this->clock);
    }

    /**
     * Restrict the test authorizer to an explicit capability allowlist. Every
     * other capability is denied, which is how a route-level capability gate is
     * proven to be the thing that actually blocks the request.
     *
     * @param  list<string>  $allowed
     */
    protected function actingAsUserWithCapabilities(array $allowed): void
    {
        $denied = array_values(array_diff(
            app(\App\Modules\IAM\Authorization\CapabilityCatalog::class)->codes(),
            $allowed,
        ));

        $this->authorizer->deniedCapabilities = $denied;
    }

    /**
     * Post a multipart request carrying an Idempotency-Key and uploaded files.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $headers
     */
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $headers
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    protected function postMultipart(string $uri, array $fields, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', $uri, $fields, [], [], $this->transformHeadersToServerVars($headers));
    }

    /**
     * Build a synthetic uploaded file; never real personal data.
     */
    protected function fakeUpload(string $name, string $contents): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, $contents);
    }
}
