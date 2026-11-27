<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Services\OrgUnitService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
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

    public function tree(Request $request, OrganizationDirectory $directory): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->asOf($request);
        $this->assertPermits($principal, 'org.tree.read', $asOf->value());
        $this->assertPermits($principal, 'org.unit.read', $asOf->value());

        $items = $directory->tree($asOf->value());

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request, OrgUnitService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertPermits($principal, 'org.unit.manage', $today->value());

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:COMPANY,DIVISION,DEPARTMENT,TEAM,COST_CENTER,SHARED_SERVICE'],
            'valid_from' => ['nullable', new BusinessDateRule],
            'valid_to' => ['nullable', new BusinessDateRule],
        ]);

        $unit = $service->open($data, $principal->userId());

        return ApiResponse::created($this->unitPayload($unit));
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

        return ApiResponse::ok($impact);
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
