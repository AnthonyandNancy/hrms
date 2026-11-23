<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflow\Contracts\ActorDirectory;
use App\Modules\Workflow\Contracts\ActorSnapshot;

final class FakeActorDirectory implements ActorDirectory
{
    /** @var array<string, ActorSnapshot> */
    private array $actors = [];

    public function add(ActorSnapshot $actor): self
    {
        $this->actors[$this->key($actor->tenantId, $actor->userId)] = $actor;

        return $this;
    }

    public function snapshot(int $tenantId, int $userId): ?ActorSnapshot
    {
        return $this->actors[$this->key($tenantId, $userId)] ?? null;
    }

    public function has(int $tenantId, int $userId): bool
    {
        return isset($this->actors[$this->key($tenantId, $userId)]);
    }

    private function key(int $tenantId, int $userId): string
    {
        return "{$tenantId}:{$userId}";
    }
}
