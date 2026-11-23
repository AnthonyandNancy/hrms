<?php

declare(strict_types=1);

namespace App\Shared;

use Illuminate\Container\Container;

/**
 * Request-scoped access to the current request id. Container scoped bindings
 * are flushed between HTTP requests, including under long-running workers.
 */
final class RequestContext
{
    private ?string $requestId = null;

    public function set(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function id(): ?string
    {
        return $this->requestId;
    }

    public static function requestId(): string
    {
        return Container::getInstance()->make(self::class)->id() ?? '';
    }
}
