<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\AllowedActionResolver;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\Lifecycle\Contracts\EmploymentContractVersionLedger;
use App\Modules\People\Contracts\ContractAccess;
use App\Modules\People\Contracts\ContractFieldPolicy;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\EmploymentContract;
use App\Modules\People\Services\EmploymentContractService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ConflictException;
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
        private readonly ContractAccess $access,
        private readonly ContractFieldPolicy $fields,
        private readonly CommandRunner $commands,
        private readonly AllowedActionResolver $allowedActions,
    ) {}

    public function index(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'people.contract.read');

        if (! $this->access->employmentVisible($principal, $employmentId, 'people.contract.read')) {
            throw new NotFoundOrForbiddenException;
        }

        $asOf = $this->businessDates->today()->value();
        $items = array_map(function (array $item) use ($principal, $asOf): array {
            $contract = EmploymentContract::query()->whereKey((int) $item['id'])->first();

            return $contract instanceof EmploymentContract
                ? $this->fields->project($principal, $contract, $item, $asOf)
                : $item;
        }, $this->contracts->list($employmentId));

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'people.contract.manage');

        if (! $this->access->employmentVisible($principal, $employmentId, 'people.contract.manage')) {
            throw new NotFoundOrForbiddenException;
        }

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
            'agreed_salary_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'agreed_currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'renewal_of_contract_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ]);

        $this->assertContractDates($data);

        $isRenewal = isset($data['renewal_of_contract_id']);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        $open = function () use ($employmentId, $data, $principal): array {
            // State-sensitive renewal checks run inside the command operation
            // so a replay of a completed renewal returns the stored response
            // instead of rejecting the now-RENEWED source contract.
            $this->assertRenewalTarget($employmentId, $data);

            $contract = $this->contracts->open(
                $employmentId,
                $data,
                $principal->userId(),
                function (array $contract) use ($employmentId, $data, $principal): void {
                    $renewal = ($contract['previous_contract_id'] ?? null) !== null;

                    $this->versions->append(
                        contractId: (int) $contract['id'],
                        employmentId: $employmentId,
                        changeType: $renewal ? 'RENEWAL' : 'CREATION',
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
                            'agreed_salary_cents' => isset($data['agreed_salary_cents']) ? (int) $data['agreed_salary_cents'] : null,
                            'agreed_currency' => isset($data['agreed_currency']) ? strtoupper((string) $data['agreed_currency']) : null,
                        ],
                        actorUserId: $principal->userId(),
                    );
                },
            );

            $stored = EmploymentContract::query()->whereKey((int) $contract['id'])->first();

            return $stored instanceof EmploymentContract
                ? $this->fields->project($principal, $stored, $contract, $this->businessDates->today()->value())
                : $contract;
        };

        // Renewals are idempotent commands: with an Idempotency-Key the whole
        // state-sensitive operation is replayed from the platform ledger.
        // Plain draft creation keeps its historical direct-write behavior.
        if ($isRenewal && $idempotencyKey !== '') {
            $result = $this->commands->run(
                new CommandDefinition(
                    name: 'people.contract.renew',
                    capability: 'people.contract.manage',
                    anchor: AnchorType::COMPANY,
                    idempotent: true,
                    invariants: [],
                    effect: null,
                    auditAction: 'people.contract.renewed',
                ),
                ['employment_id' => $employmentId, ...$data],
                fn (): array => [
                    'http_status' => 201,
                    'body' => $open(),
                    'resource_type' => 'employment_contract',
                    'resource_id' => '',
                ],
                $idempotencyKey,
            );

            $response = $result->replayed ? ApiResponse::ok($result->body) : ApiResponse::created($result->body);

            if ($result->replayed) {
                $response->headers->set('Idempotency-Replayed', 'true');
            }

            return $response;
        }

        return ApiResponse::created($open());
    }

    /**
     * The contract row stores the current probation/work-location values; the
     * creation snapshot below preserves the values as signed. The controller
     * appends the creation version inside the same service transaction so a
     * ledger failure rolls the contract row back.
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

        if (! in_array((string) $previous->status, EmploymentContract::OPERATIONAL_STATUSES, true)) {
            throw new ConflictException(
                'The selected contract can no longer be renewed.',
                ['reason' => 'CONTRACT_NOT_RENEWABLE'],
            );
        }

        if ($previous->renewed_by_id !== null) {
            throw new ConflictException(
                'The selected contract already has a renewal.',
                ['reason' => 'CONTRACT_ALREADY_RENEWED'],
            );
        }

        // Expiry is derived from the company business date, not the stored
        // status: an ACTIVE row whose end date has passed is not renewable.
        if ($previous->end_date !== null
            && BusinessDate::from((string) $previous->end_date)->isBefore(BusinessDate::from($this->businessDates->today()->value()))) {
            throw new ConflictException(
                'The selected contract can no longer be renewed.',
                ['reason' => 'CONTRACT_NOT_RENEWABLE'],
            );
        }
    }

    public function show(Request $request, int $employmentId, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'people.contract.read');

        $contract = $this->access->contract($principal, $id, 'people.contract.read');

        if ((int) $contract->employment_id !== $employmentId) {
            throw new NotFoundOrForbiddenException;
        }

        $asOf = $this->businessDates->today()->value();
        $history = $this->versions->history($id);
        $latest = $history !== [] ? $history[count($history) - 1] : [];
        $detail = $this->fields->project(
            $principal,
            $contract,
            [
                ...$this->contracts->show($employmentId, $id),
                'agreed_salary_cents' => $latest['agreed_salary_minor'] ?? null,
                'agreed_currency' => $latest['agreed_currency'] ?? null,
                'source_version' => $latest['terms_hash'] ?? null,
            ],
            $asOf,
        );
        $assignment = Assignment::query()
            ->where('employment_id', (int) $contract->employment_id)
            ->where('valid_from', '<=', $asOf)
            ->where('valid_to', '>', $asOf)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return ApiResponse::ok([
            ...$detail,
            'state' => (string) $contract->status,
            'version' => (int) $contract->version,
            'allowed_actions' => $this->allowedActions->for(
                $principal,
                'contract',
                (int) $contract->id,
                [
                    'status' => (string) $contract->status,
                    'subject_employment_id' => (int) $contract->employment_id,
                    'org_unit_id' => $assignment !== null ? (int) $assignment->org_unit_id : null,
                ],
                $asOf,
            ),
        ]);
    }

    /**
     * Version ledger for the employee contract selector. Only versions that
     * belong to the scoped employment are returned; Payroll uses the stable
     * version id and terms hash rather than re-reading the current parent row.
     */
    public function versions(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'people.contract.read');

        if (! $this->access->employmentVisible($principal, $employmentId, 'people.contract.read')) {
            throw new NotFoundOrForbiddenException;
        }

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $asOf = isset($data['as_of'])
            ? (string) $data['as_of']
            : $this->businessDates->today()->value();
        $contractIds = EmploymentContract::query()
            ->where('employment_id', $employmentId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
        $items = [];

        foreach ($contractIds as $contractId) {
            foreach ($this->versions->history($contractId) as $version) {
                if ((string) $version['effective_from'] <= $asOf) {
                    $items[] = $version;
                }
            }
        }

        usort($items, static function (array $left, array $right): int {
            return [$left['effective_from'], $left['contract_id'], $left['id']]
                <=> [$right['effective_from'], $right['contract_id'], $right['id']];
        });

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function expiring(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'people.contract.read');
        $data = $request->validate([
            'within_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $asOf = isset($data['as_of'])
            ? (string) $data['as_of']
            : $this->businessDates->today()->value();
        // A custom expiry projection must not bypass row scope. Reuse the
        // ledger row-scope check before returning any expiring row.
        $items = array_values(array_filter(
            $this->contracts->expiring(
                $asOf,
                (int) ($data['within_days'] ?? 60),
            ),
            fn (array $item): bool => $this->access->employmentVisible(
                $principal,
                (int) $item['employment_id'],
                'people.contract.read',
                $asOf,
            ),
        ));

        $items = array_map(function (array $item) use ($principal, $asOf): array {
            $contract = EmploymentContract::query()->whereKey((int) $item['id'])->first();

            return $contract instanceof EmploymentContract
                ? $this->fields->project($principal, $contract, $item, $asOf)
                : $item;
        }, $items);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    private function assertCapability(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
