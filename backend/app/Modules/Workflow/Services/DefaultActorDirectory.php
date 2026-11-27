<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\ActorDirectory;
use App\Modules\Workflow\Contracts\ActorSnapshot;

/**
 * Reference binding until IAM integration. Workflow records the principal's
 * own authorization snapshot even when no richer directory is wired.
 */
final class DefaultActorDirectory implements ActorDirectory
{
    public function snapshot(int $userId): ?ActorSnapshot
    {
        return null;
    }
}
