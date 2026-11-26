<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Lifecycle\Contracts\EmploymentContractVersionLedger;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use App\Modules\People\Services\EmploymentContractService;
use App\Shared\ApiResponse;
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

final class EmploymentContractController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly EmploymentContractService $contracts,
        private readonly EmploymentContractVersionLedger $versions,
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
            'probation_end_date' => ['nullable', new BusinessDateRule],
            'work_location' => ['nullable', 'string', 'max:160'],
            'signed_on' => ['nullable', new BusinessDateRule],
            'status' => ['sometimes', 'string', 'in:DRAFT,ACTIVE'],
            'terms_summary' => ['nullable', 'string', 'max:2000'],
            'renewal_of_contract_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ]);

        $this->assertContractDates($data);
        $this->assertRenewalTarget($employmentId, $data);

        // The service commits the contract row, then the ledger port appends
        // the creation/renewal version. The ledger is append-only, so a later
        // failure can never rewrite earlier history; the version row is the
        // first-class evidence of this write.
        $contract = $this->contracts->open($employmentId, $data, $principal->userId());
        $isRenewal = ($contract['previous_contract_id'] ?? null) !== null;

        $this->versions->append(
            contractId: (int) $contract['id'],
            employmentId: $employmentId,
            changeType: $isRenewal ? 'RENEWAL' : 'CREATION',
            effectiveFrom: (string) $contract['start_date'],
            effectiveTo: $contract['end_date'] !== null ? (string) $contract['end_date'] : BusinessDate::OPEN_END,
            reason: isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null,
            snapshot: [
                'contract_no' => (string) $contract['contract_no'],
                'contract_type' => (string) $contract['contract_type'],
                'start_date' => (string) $contract['start_date'],
                'end_date' => $contract['end_date'],
                'signed_on' => $contract['signed_on'],
                'status' => (string) $contract['status'],
                'probation_end_date' => $data['probation_end_date'] ?? null,
                'work_location' => $data['work_location'] ?? null,
                'terms_summary' => $data['terms_summary'] ?? null,
                'previous_contract_id' => $contract['previous_contract_id'],
            ],
            actorUserId: $principal->userId(),
        );

        return ApiResponse::created($contract);
    }

    /**
     * The contract table has no probation/work-location columns of its own;
     * those fields are part of the contract record and therefore kept in the
     * append-only version snapshot. The controller appends the creation
     * version after the contract row so every contract has readable history.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertContractDates(array $data): void
    {
        $start = BusinessDate::from((string) $data['start_date']);
        $end = isset($data['end_date']) ? BusinessDate::from((string) $data['end_date']) : null;

        if ($end !== null && ! $end->isAfter($start)) {
            throw new ValidationException(['end_date' => ['The end date must be after the start date.']]);
        }

        if (isset($data['probation_end_date'])) {
            $probation = BusinessDate::from((string) $data['probation_end_date']);

            if ($probation->isBefore($start)) {
                throw new ValidationException(['probation_end_date' => ['The probation end date cannot be before the contract start date.']]);
            }

            if ($end !== null && $probation->isAfter($end)) {
                throw new ValidationException(['probation_end_date' => ['The probation end date must fall inside the contract.']]);
            }

            if ($probation->isAfter($start->addDays(183))) {
                throw new ValidationException(['probation_end_date' => ['The probation period cannot exceed six months.']]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertRenewalTarget(int $employmentId, array $data): void
    {
        $renewalOf = $data['renewal_of_contract_id'] ?? null;

        if ($renewalOf === null) {
            return;
        }

        if (! isset($data['reason']) || trim((string) $data['reason']) === '') {
            throw new ValidationException(['reason' => ['A renewal reason is required.']]);
        }

        $previous = EmploymentContract::query()
            ->whereKey((int) $renewalOf)
            ->where('employment_id', $employmentId)
            ->first();

        if ($previous === null) {
            throw new ValidationException(['renewal_of_contract_id' => ['The previous contract does not exist for this employee.']]);
        }

        if ((string) $previous->status === 'TERMINATED' || (string) $previous->status === 'RENEWED') {
            throw new ValidationException(['renewal_of_contract_id' => ['The selected contract can no longer be renewed.']]);
        }
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
