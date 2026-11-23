<?php

declare(strict_types=1);

namespace App\Shared;

use Illuminate\Container\Container;

/**
 * Request-scoped access to correlation and actor context. Container scoped
 * bindings are flushed between HTTP requests, including under long-running
 * workers. Tenant 0 is the unauthenticated/platform scope.
 */
final class RequestContext
{
    private ?string $requestId = null;

    private int $tenantId = 0;

    private ?int $userId = null;

    private ?int $employmentId = null;

    public function set(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function id(): ?string
    {
        return $this->requestId;
    }

    public function setActor(int $tenantId, ?int $userId = null, ?int $employmentId = null): void
    {
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->employmentId = $employmentId;
    }

    public static function tenantId(): int
    {
        return Container::getInstance()->make(self::class)->tenantId;
    }

    public static function userId(): ?int
    {
        return Container::getInstance()->make(self::class)->userId;
    }

    public static function employmentId(): ?int
    {
        return Container::getInstance()->make(self::class)->employmentId;
    }

    public static function requestId(): string
    {
        return Container::getInstance()->make(self::class)->id() ?? '';
    }
}
