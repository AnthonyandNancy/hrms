<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Time\Contracts\TimeActor;
use App\Shared\Auth\AuthenticatedPrincipal;
use App\Shared\Authorization\Authorizer;
use Tests\Support\AllowAllAuthorizer;
use Tests\Support\TestPrincipal;

abstract class TimeHttpTestCase extends TimeTestCase
{
    protected AllowAllAuthorizer $httpAuthorizer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hrms.default_timezone', 'Asia/Shanghai');

        $this->httpAuthorizer = new AllowAllAuthorizer;
        $this->app->instance(Authorizer::class, new TimeTestAuthorizer($this->actors));
    }

    protected function actAsHttp(TimeActor $actor, ?int $userId = null): void
    {
        $this->actAsTimeActor($actor);
        $principal = new TestPrincipal(
            userId: $userId ?? $actor->userId,
            employmentId: $actor->employmentId,
        );

        $this->app->instance(Principal::class, $principal);
        $this->app->instance(AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);
    }

    protected function forgetHttpPrincipal(): void
    {
        $this->actors->setActor(null);
        $this->app->forgetInstance(Principal::class);
        $this->app->forgetInstance(AuthenticatedPrincipal::class);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->clear();
    }
}
