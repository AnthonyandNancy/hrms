<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Models\Employment;
use App\Modules\People\Services\EmploymentContractService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmploymentContractController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
        private readonly EmploymentContractService $contracts,
    ) {}

    public function index(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertEmployment($principal, $employmentId);
        $this->assertCapability($principal, 'people.contract.read');

        $items = $this->contracts->list($principal->tenantId(), $employmentId);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertEmployment($principal, $employmentId);
        $this->assertCapability($principal, 'people.contract.manage');

        $data = $request->validate([
            'contract_no' => ['required', 'string', 'max:80'],
            'contract_type' => ['required', 'string', 'in:FIXED_TERM,OPEN_ENDED,INTERNSHIP,CONSULTANT'],
            'start_date' => ['required', new BusinessDateRule],
            'end_date' => ['nullable', new BusinessDateRule],
            'signed_on' => ['nullable', new BusinessDateRule],
            'status' => ['sometimes', 'string', 'in:DRAFT,ACTIVE'],
            'terms_summary' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::created($this->contracts->open($principal->tenantId(), $employmentId, $data, $principal->userId()));
    }

    public function show(Request $request, int $employmentId, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertEmployment($principal, $employmentId);
        $this->assertCapability($principal, 'people.contract.read');

        return ApiResponse::ok($this->contracts->show($principal->tenantId(), $employmentId, $id));
    }

    public function expiring(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'people.contract.read');
        $data = $request->validate([
            'within_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'as_of' => ['nullable', new BusinessDateRule],
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $asOf = $data['as_of'] ?? $this->businessDates->today($principal->tenantId())->value();
        $items = $this->contracts->expiring(
            $principal->tenantId(),
            $asOf,
            (int) ($data['within_days'] ?? 60),
            isset($data['legal_entity_id']) ? (int) $data['legal_entity_id'] : null,
        );

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    private function assertEmployment(Principal $principal, int $employmentId): void
    {
        if (! Employment::query()->where('tenant_id', $principal->tenantId())->whereKey($employmentId)->exists()) {
            throw new NotFoundOrForbiddenException;
        }
    }

    private function assertCapability(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today($principal->tenantId())->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
