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
        $this->actors[$this->key($actor->userId)] = $actor;

        return $this;
    }

    public function snapshot(int $userId): ?ActorSnapshot
    {
        return $this->actors[$this->key($userId)] ?? null;
    }

    public function has(int $userId): bool
    {
        return isset($this->actors[$this->key($userId)]);
    }

    private function key(int $userId): string
    {
        return (string) $userId;
    }
}
