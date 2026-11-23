<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Services\OrgUnitService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrgUnitController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly Clock $clock,
    ) {}

    public function tree(Request $request, OrganizationDirectory $directory): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->asOf($request, $principal->tenantId());
        $this->assertAllows($principal, 'org.tree.read', AnchorRef::global(), $asOf->value());
        $this->assertAllows($principal, 'org.unit.read', AnchorRef::global(), $asOf->value());

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'legal_entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $legalEntity = LegalEntity::query()
            ->where('tenant_id', $principal->tenantId())
            ->whereKey((int) $data['legal_entity_id'])
            ->first();

        if ($legalEntity === null) {
            throw new NotFoundOrForbiddenException;
        }

        $items = $directory->tree($principal->tenantId(), (int) $legalEntity->id, $asOf->value());

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request, OrgUnitService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->today($principal->tenantId());
        $this->assertAllows($principal, 'org.unit.manage', AnchorRef::global(), $today->value());

        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:COMPANY,DIVISION,DEPARTMENT,TEAM,COST_CENTER,SHARED_SERVICE'],
            'valid_from' => ['nullable', new BusinessDateRule],
            'valid_to' => ['nullable', new BusinessDateRule],
        ]);

        $unit = $service->open($principal->tenantId(), $data, $principal->userId());

        return ApiResponse::created($this->unitPayload($unit));
    }

    public function move(Request $request, int $id, OrgUnitService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->today($principal->tenantId());
        $this->assertAllows($principal, 'org.unit.manage', AnchorRef::global(), $today->value());

        $data = $request->validate([
            'new_parent_id' => ['present', 'nullable', 'integer', 'min:1'],
            'effective_from' => ['required', new BusinessDateRule],
        ]);

        $result = $service->move($principal->tenantId(), $id, $data, $principal->userId());

        return ApiResponse::ok([
            'moved' => true,
            'old_org_unit_id' => $result['old_id'],
            'org_unit' => $this->unitPayload($result['org_unit']),
        ]);
    }

    public function impact(Request $request, int $id, OrgUnitService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->asOf($request, $principal->tenantId());
        $this->assertAllows($principal, 'org.unit.manage', AnchorRef::global(), $asOf->value());

        $data = $request->validate([
            'new_parent_id' => ['present', 'nullable', 'integer', 'min:1'],
            'as_of' => ['nullable', new BusinessDateRule],
        ]);

        $impact = $service->impact($principal->tenantId(), $id, $data, $asOf->value());

        return ApiResponse::ok($impact);
    }

    private function asOf(Request $request, int $tenantId): BusinessDate
    {
        $value = $request->query('as_of');

        if ($value === null || $value === '') {
            return $this->today($tenantId);
        }

        if (! is_string($value) || ($date = BusinessDate::tryFrom($value)) === null) {
            throw new ValidationException(['as_of' => ['The as_of field must be a real calendar date in YYYY-MM-DD format.']]);
        }

        return $date;
    }

    private function today(int $tenantId): BusinessDate
    {
        $timezone = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('timezone');

        return BusinessDate::today(is_string($timezone) ? $timezone : 'UTC', $this->clock);
    }

    private function assertAllows(Principal $principal, string $capability, AnchorRef $anchor, string $on): void
    {
        if (! $this->authorizer->allows($principal, $capability, $anchor, $on)) {
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
            'legal_entity_id' => (int) $unit->legal_entity_id,
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
