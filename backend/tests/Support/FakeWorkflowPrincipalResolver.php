<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;

final class FakeWorkflowPrincipalResolver implements WorkflowPrincipalResolver
{
    private ?WorkflowPrincipal $principal = null;

    public function setPrincipal(?WorkflowPrincipal $principal): void
    {
        $this->principal = $principal;
    }

    public function resolve(): ?WorkflowPrincipal
    {
        return $this->principal;
    }
}
