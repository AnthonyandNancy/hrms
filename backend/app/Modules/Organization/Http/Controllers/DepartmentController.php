<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\People\Contracts\DepartmentMembershipPort;
use App\Modules\People\Contracts\DepartmentStaffingDirectory;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DepartmentController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly DepartmentStaffingDirectory $staffing,
        private readonly DepartmentMembershipPort $membership,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.read');
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'q' => ['nullable', 'string', 'max:160'],
        ]);
        $on = $data['as_of'] ?? $this->businessDates->today()->value();

        $query = OrgUnit::query()
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderBy('code');

        if (isset($data['q']) && $data['q'] !== '') {
            $query->where(function ($builder) use ($data): void {
                $builder->where('code', 'like', '%'.$data['q'].'%')
                    ->orWhere('name', 'like', '%'.$data['q'].'%');
            });
        }

        $units = $query->get();
        $ids = $units->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $headcount = $this->staffing->activeHeadcount($ids, $on);

        $items = $units->map(fn (OrgUnit $unit): array => $this->payload(
            $unit,
            $headcount[(int) $unit->id] ?? 0,
        ))->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function members(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.read');
        $this->assertUnit($id);
        $data = $request->validate(['as_of' => ['nullable', new BusinessDateRule]]);
        $on = $data['as_of'] ?? $this->businessDates->today()->value();

        $items = $this->staffing->members($id, $on);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function addMember(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.manage');
        $this->assertUnit($id);

        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', new BusinessDateRule],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $result = $this->membership->moveMember(
            $id,
            (int) $data['employment_id'],
            (string) $data['effective_from'],
            $principal->userId(),
        );

        return ApiResponse::created([...$result, 'reason' => (string) $data['reason']]);
    }

    public function staffing(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.read');
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $on = $data['as_of'] ?? $this->businessDates->today()->value();

        $units = OrgUnit::query()
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderBy('code')
            ->get();

        $ids = $units->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $headcount = $this->staffing->activeHeadcount($ids, $on);

        $items = $units->map(static function (OrgUnit $unit) use ($headcount): array {
            $staffed = $headcount[(int) $unit->id] ?? 0;
            $limit = $unit->getAttribute('headcount_limit');

            return [
                'org_unit_id' => (int) $unit->id,
                'code' => (string) $unit->code,
                'name' => (string) $unit->name,
                'headcount_limit' => $limit !== null ? (int) $limit : null,
                'staffed' => $staffed,
                'vacancies' => $limit !== null ? max(0, (int) $limit - $staffed) : null,
                'overstaffed' => $limit !== null && $staffed > (int) $limit,
            ];
        })->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    private function assertUnit(int $id): OrgUnit
    {
        $unit = OrgUnit::query()->whereKey($id)->first();

        if ($unit === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $unit;
    }

    private function assertCapability(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OrgUnit $unit, int $headcount): array
    {
        return [
            'id' => (int) $unit->id,
            'parent_id' => $unit->parent_id !== null ? (int) $unit->parent_id : null,
            'code' => (string) $unit->code,
            'name' => (string) $unit->name,
            'type' => (string) $unit->type,
            'status' => (string) $unit->status,
            'headcount_limit' => $unit->getAttribute('headcount_limit') !== null ? (int) $unit->getAttribute('headcount_limit') : null,
            'headcount' => $headcount,
            'direct_headcount' => $headcount,
            'valid_from' => (string) $unit->valid_from,
            'valid_to' => (string) $unit->valid_to,
        ];
    }
}
