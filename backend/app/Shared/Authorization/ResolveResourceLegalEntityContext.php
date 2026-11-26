<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derives the legal-entity anchor for resource-scoped routes from the owned
 * resource, not from client input.
 *
 * A position id resolves through the Organization facts contract; an
 * employment id resolves through the People lifecycle contract. The resolved
 * entity is merged into the request under `legal_entity_id`, which is the
 * parameter the following `capability:...,COMPANY,legal_entity_id`
 * middleware reads. Unknown or out-of-tenant resources become 404 before any
 * capability question is asked, so an id cannot be used to probe scope.
 */
final class ResolveResourceLegalEntityContext
{
    public function __construct(
        private readonly OrganizationFacts $organization,
        private readonly EmploymentLifecycleDirectory $employments,
    ) {}

    public function handle(Request $request, Closure $next, string $source = 'position'): Response
    {
        $principal = $this->principal($request);

        if (! $principal instanceof Principal) {
            return ApiResponse::error(ErrorCode::UNAUTHENTICATED->value, 'Authentication is required.', 401, ['reason' => 'UNAUTHENTICATED']);
        }

        $legalEntityId = match ($source) {
            'position' => $this->legalEntityForPosition($principal->tenantId(), $request),
            'employment' => $this->legalEntityForEmployment($principal->tenantId(), $request),
            default => null,
        };

        if ($legalEntityId === null) {
            return ApiResponse::error(ErrorCode::NOT_FOUND->value, 'The requested resource was not found.', 404, ['reason' => 'OUT_OF_SCOPE']);
        }

        $request->merge(['legal_entity_id' => $legalEntityId]);
        $request->attributes->set('resolved_legal_entity_id', $legalEntityId);

        return $next($request);
    }

    private function principal(Request $request): ?Principal
    {
        $principal = $request->attributes->get('iam.principal')
            ?? $request->attributes->get('principal');

        if (! $principal instanceof Principal && app()->environment('testing') && app()->bound(Principal::class)) {
            $principal = app(Principal::class);
        }

        return $principal instanceof Principal ? $principal : null;
    }

    private function legalEntityForPosition(int $tenantId, Request $request): ?int
    {
        $id = $request->route('id');

        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        $position = $this->organization->position($tenantId, (int) $id);

        return $position?->legalEntityId;
    }

    private function legalEntityForEmployment(int $tenantId, Request $request): ?int
    {
        $employmentId = $request->route('employment_id') ?? $request->input('employment_id');

        if (! is_numeric($employmentId) || (int) $employmentId <= 0) {
            return null;
        }

        $employment = $this->employments->employment($tenantId, (int) $employmentId);

        return $employment?->legalEntityId;
    }
}
