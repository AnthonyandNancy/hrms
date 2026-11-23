<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Time\Contracts\EmploymentSnapshot;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Contracts\WorkflowCaseSnapshot;
use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Contracts\TimeActorResolver;
use Illuminate\Http\Request;

final class FakeTimeActorResolver implements TimeActorResolver
{
    private ?TimeActor $actor = null;

    public function setActor(?TimeActor $actor): void
    {
        $this->actor = $actor;
    }

    public function resolve(Request $request): ?TimeActor
    {
        return $this->actor;
    }
}
