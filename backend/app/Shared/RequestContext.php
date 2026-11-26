<?php

declare(strict_types=1);

namespace App\Shared;

use Illuminate\Container\Container;

/**
 * Request-scoped access to correlation and actor context. Container scoped
 * bindings are flushed between HTTP requests, including under long-running
 * workers.
 */
final class RequestContext
{
    private ?string $requestId = null;

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

    public function setActor(?int $userId = null, ?int $employmentId = null): void
    {
        $this->userId = $userId;
        $this->employmentId = $employmentId;
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
