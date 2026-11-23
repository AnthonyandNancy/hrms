<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;

/**
 * Test-only bridge between Workflow's narrow principal fixture and IAM's
 * route-level capability middleware. Production Workflow routes resolve the
 * IAM principal; this adapter keeps the workflow vertical's original test
 * fixture authoritative without weakening the real engine.
 */
final class WorkflowAuthorizerBridge implements Authorizer
{
    public function __construct(private readonly WorkflowPrincipalResolver $resolver) {}

    public function constrain(Builder $query, Principal $principal, string $capability, string $businessDate): Builder
    {
        return $query;
    }

    public function permits(Principal $principal, string $capability, string $businessDate): bool
    {
        return $this->allows($principal, $capability, AnchorRef::self(), $businessDate);
    }

    public function allows(Principal $principal, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        $workflow = $this->resolver->resolve();

        return $workflow !== null
            && $workflow->tenantId === $principal->tenantId()
            && $workflow->userId === $principal->userId()
            && $workflow->can($capability);
    }

    public function fields(Principal $principal, string $capability, ResourceRef $row): FieldSet
    {
        return new FieldSet(['*' => FieldProfile::P0_PUBLIC], exportUnlocked: false);
    }
}
