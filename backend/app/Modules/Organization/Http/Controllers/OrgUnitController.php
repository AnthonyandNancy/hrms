<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Services\OrgUnitService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrgUnitController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    /**
     * The /v1/org-units create writer was retired in M05. The route token is
     * still owned by routes/api.php (outside this module's change boundary),
     * but this action must never open a second org unit. It returns the
     * standard not-found envelope and performs no write.
     */
    public function store(): JsonResponse
    {
        throw new NotFoundOrForbiddenException;
    }

    public function tree(Request $request, OrganizationDirectory $directory): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->asOf($request);
        $this->assertPermits($principal, 'org.tree.read', $asOf->value());
        $this->assertPermits($principal, 'org.unit.read', $asOf->value());

        $items = $directory->tree($asOf->value());

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function move(Request $request, int $id, OrgUnitService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertPermits($principal, 'org.unit.manage', $today->value());

        $data = $request->validate([
            'new_parent_id' => ['present', 'nullable', 'integer', 'min:1'],
            'effective_from' => ['required', new BusinessDateRule],
        ]);

        $result = $service->move($id, $data, $principal->userId());

        return ApiResponse::ok([
            'moved' => true,
            'old_org_unit_id' => $result['old_id'],
            'org_unit' => $this->unitPayload($result['org_unit']),
        ]);
    }

    public function impact(Request $request, int $id, OrgUnitService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->asOf($request);
        $this->assertPermits($principal, 'org.unit.manage', $asOf->value());

        $data = $request->validate([
            'new_parent_id' => ['present', 'nullable', 'integer', 'min:1'],
            'as_of' => ['nullable', new BusinessDateRule],
        ]);

        $impact = $service->impact($id, $data, $asOf->value());
        $newParentId = isset($data['new_parent_id']) ? (int) $data['new_parent_id'] : null;

        return ApiResponse::ok([
            ...$impact,
            'authorization_warning' => $this->authorizationWarning($principal, $id, $newParentId, $asOf->value()),
        ]);
    }

    /**
     * A move is allowed only when the caller's capability covers both the
     * moving unit and the target unit. This is a preview warning, not a second
     * authorization system: move() still executes behind route middleware.
     */
    private function authorizationWarning(Principal $principal, int $sourceId, ?int $targetId, string $on): ?string
    {
        $sourceAllowed = $this->authorizer->allows($principal, 'org.unit.manage', AnchorRef::orgUnit($sourceId), $on)
            || $this->authorizer->allows($principal, 'org.unit.manage', AnchorRef::orgSubtree($sourceId), $on);
        $targetAllowed = $targetId === null
            || $this->authorizer->allows($principal, 'org.unit.manage', AnchorRef::orgUnit($targetId), $on)
            || $this->authorizer->allows($principal, 'org.unit.manage', AnchorRef::orgSubtree($targetId), $on);

        return $sourceAllowed && $targetAllowed
            ? null
            : '组织权限仅覆盖当前部门或目标部门之一；确认提交前请核对数据范围。';
    }

    private function asOf(Request $request): BusinessDate
    {
        $value = $request->query('as_of');

        if ($value === null || $value === '') {
            return $this->businessDates->today();
        }

        if (! is_string($value) || ($date = BusinessDate::tryFrom($value)) === null) {
            throw new ValidationException(['as_of' => ['The as_of field must be a real calendar date in YYYY-MM-DD format.']]);
        }

        return $date;
    }

    private function assertPermits(Principal $principal, string $capability, string $on): void
    {
        if (! $this->authorizer->permits($principal, $capability, $on)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(OrgUnit $unit): array
    {
        return [
            'id' => (int) $unit->id,
            'parent_id' => $unit->parent_id !== null ? (int) $unit->parent_id : null,
            'code' => (string) $unit->code,
            'name' => (string) $unit->name,
            'type' => (string) $unit->type,
            'status' => (string) $unit->status,
            'valid_from' => (string) $unit->valid_from,
            'valid_to' => (string) $unit->valid_to,
        ];
    }
}
