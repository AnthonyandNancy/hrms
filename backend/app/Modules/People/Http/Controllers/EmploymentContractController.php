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
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmploymentContractController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly EmploymentContractService $contracts,
    ) {}

    public function index(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertEmployment($principal, $employmentId);
        $this->assertCapability($principal, 'people.contract.read');

        $items = $this->contracts->list($employmentId);

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

        return ApiResponse::created($this->contracts->open($employmentId, $data, $principal->userId()));
    }

    public function show(Request $request, int $employmentId, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertEmployment($principal, $employmentId);
        $this->assertCapability($principal, 'people.contract.read');

        return ApiResponse::ok($this->contracts->show($employmentId, $id));
    }

    public function expiring(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'people.contract.read');
        $data = $request->validate([
            'within_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $asOf = $data['as_of'] ?? $this->businessDates->today()->value();
        $items = $this->contracts->expiring(
            $asOf,
            (int) ($data['within_days'] ?? 60),
        );

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    private function assertEmployment(Principal $principal, int $employmentId): void
    {
        $today = $this->businessDates->today()->value();
        $visible = $this->authorizer->constrain(
            Employment::query()->whereKey($employmentId),
            $principal,
            'people.contract.read',
            $today,
        )->exists();

        if (! $visible) {
            throw new NotFoundOrForbiddenException;
        }
    }

    private function assertCapability(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
