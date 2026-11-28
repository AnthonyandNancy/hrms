<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Lifecycle\Contracts\EmploymentContractVersionLedger;
use App\Modules\Payroll\Models\EmployeeCompensation;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Models\SalaryAdjustment;
use App\Modules\People\Contracts\EmploymentTermsDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Modules\Workflow\Contracts\WorkflowCaseRecord;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Money\Money;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated compensation archive and case-approved salary adjustments.
 *
 * Money is accepted and stored in integer minor units only. A new effective
 * date appends a version; existing versions are never updated in place.
 */
final class CompensationService
{
    public function __construct(
        private readonly PeopleDirectory $people,
        private readonly EmploymentTermsDirectory $terms,
        private readonly WorkflowCaseGateway $workflow,
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly SensitiveAccessAuditor $sensitiveAuditor,
        private readonly Clock $clock,
        private readonly EmploymentContractVersionLedger $contractVersions,
    ) {}

    /**
     * @param  array{employment_id?: int|null}  $filters
     * @return array{rows: Collection<int, EmployeeCompensation>, total: int}
     */
    public function listCompensations(Principal $principal, string $businessDate, array $filters, int $page, int $pageSize): array
    {
        $query = $this->authorizer->constrain(
            EmployeeCompensation::query(),
            $principal,
            'payroll.compensation.read',
            $businessDate,
        );

        if (($filters['employment_id'] ?? null) !== null) {
            $query->where('employment_id', (int) $filters['employment_id']);
        }

        $total = (int) (clone $query)->count();

        return [
            'rows' => $query->orderBy('employment_id')->orderByDesc('effective_from')->orderByDesc('id')->forPage($page, $pageSize)->get(),
            'total' => $total,
        ];
    }

    /**
     * @param  array{employment_id?: int|null}  $filters
     * @return array{rows: Collection<int, SalaryAdjustment>, total: int}
     */
    public function listAdjustments(Principal $principal, string $businessDate, array $filters, int $page, int $pageSize): array
    {
        $query = $this->authorizer->constrain(
            SalaryAdjustment::query(),
            $principal,
            'payroll.compensation.read',
            $businessDate,
        );

        if (($filters['employment_id'] ?? null) !== null) {
            $query->where('employment_id', (int) $filters['employment_id']);
        }

        $total = (int) (clone $query)->count();

        return [
            'rows' => $query->orderByDesc('effective_from')->orderByDesc('id')->forPage($page, $pageSize)->get(),
            'total' => $total,
        ];
    }

    /**
     * @param  array{
     *     employment_id: int,
     *     effective_from: string,
     *     base_salary_cents: int,
     *     currency?: string,
     *     salary_scheme_code?: string|null,
     *     contract_version_id?: int|null,
     *     payroll_scheme_id?: int|null,
     *     payroll_scheme_version?: int|null,
     *     parameter_overrides?: array<string, scalar|null>|null,
     *     effective_to?: string|null,
     *     status?: string|null,
     *     approved_case_id?: int|null,
     *     source_type?: string|null,
     *     source_id?: int|null,
     *     supersedes_id?: int|null
     * }  $data
     */
    public function createCompensation(
        int $actorUserId,
        array $data,
        ?int $caseId = null,
        string $capability = 'payroll.compensation.manage',
    ): EmployeeCompensation {
        $employmentId = (int) $data['employment_id'];
        $effectiveFrom = BusinessDate::from((string) $data['effective_from']);
        $baseSalary = Money::fromMinor((int) $data['base_salary_cents'], (string) ($data['currency'] ?? 'CNY'));
        $employment = $this->people->employmentSnapshot($employmentId, $effectiveFrom->value());

        if ($employment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $effectiveTo = isset($data['effective_to'])
            ? BusinessDate::from((string) $data['effective_to'])
            : null;

        if ($effectiveTo !== null && ! $effectiveTo->isAfter($effectiveFrom)) {
            throw new ValidationException(['effective_to' => ['The end date must be after the effective date.']]);
        }

        $contractVersion = null;

        if (isset($data['contract_version_id'])) {
            $contractVersion = $this->contractVersions->findVersion((int) $data['contract_version_id']);

            if ($contractVersion === null || (int) ($contractVersion['employment_id'] ?? 0) !== $employmentId) {
                throw new ConflictException('The selected contract version belongs to another employment.', [
                    'reason' => 'COMPENSATION_CONTRACT_EMPLOYMENT_MISMATCH',
                ]);
            }

            $contractFrom = (string) ($contractVersion['effective_from'] ?? '');
            $contractTo = (string) ($contractVersion['effective_to'] ?? BusinessDate::OPEN_END);

            if ($contractFrom === '' || $effectiveFrom->value() < $contractFrom || $effectiveFrom->value() > $contractTo) {
                throw new ConflictException('The compensation effective date is outside the contract version.', [
                    'reason' => 'COMPENSATION_CONTRACT_NOT_EFFECTIVE',
                ]);
            }

            $contractCurrency = $contractVersion['agreed_currency'] ?? null;

            if (is_string($contractCurrency) && strtoupper($contractCurrency) !== $baseSalary->currency()) {
                throw new ConflictException('Compensation currency must match the agreed contract currency.', [
                    'reason' => 'COMPENSATION_CURRENCY_MISMATCH',
                ]);
            }
        }

        $scheme = null;

        if (isset($data['payroll_scheme_id'])) {
            $scheme = PayrollScheme::query()->whereKey((int) $data['payroll_scheme_id'])->first();

            if ($scheme === null || (string) $scheme->status !== 'ACTIVE') {
                throw new ConflictException('The payroll scheme is not active.', [
                    'reason' => 'COMPENSATION_SCHEME_NOT_ACTIVE',
                ]);
            }

            $schemeFrom = $scheme->valid_from instanceof \DateTimeInterface
                ? $scheme->valid_from->format('Y-m-d')
                : substr((string) $scheme->valid_from, 0, 10);
            $schemeTo = $scheme->valid_to instanceof \DateTimeInterface
                ? $scheme->valid_to->format('Y-m-d')
                : substr((string) $scheme->valid_to, 0, 10);

            if ($effectiveFrom->value() < $schemeFrom || $effectiveFrom->value() > $schemeTo) {
                throw new ConflictException('The payroll scheme is not effective on the compensation date.', [
                    'reason' => 'COMPENSATION_SCHEME_NOT_EFFECTIVE',
                ]);
            }

            if (strtoupper((string) $scheme->currency) !== $baseSalary->currency()) {
                throw new ConflictException('Compensation currency must match the payroll scheme currency.', [
                    'reason' => 'COMPENSATION_CURRENCY_MISMATCH',
                ]);
            }
        }

        $parameterOverrides = $this->normalizeParameterOverrides($data['parameter_overrides'] ?? []);
        $status = strtoupper((string) ($data['status'] ?? 'ACTIVE'));

        if (! in_array($status, ['DRAFT', 'PENDING_APPROVAL', 'SCHEDULED', 'ACTIVE', 'SUPERSEDED', 'ENDED'], true)) {
            throw new ValidationException(['status' => ['Unsupported compensation status.']]);
        }

        $contentHash = hash('sha256', json_encode([
            'employment_id' => $employmentId,
            'contract_version_id' => $data['contract_version_id'] ?? null,
            'effective_from' => $effectiveFrom->value(),
            'effective_to' => $effectiveTo?->value(),
            'status' => $status,
            'currency' => $baseSalary->currency(),
            'base_salary_cents' => $baseSalary->minor(),
            'salary_scheme_code' => $data['salary_scheme_code'] ?? null,
            'payroll_scheme_id' => $data['payroll_scheme_id'] ?? null,
            'payroll_scheme_version' => $scheme?->version,
            'parameter_overrides' => $parameterOverrides,
            'approved_case_id' => $data['approved_case_id'] ?? $caseId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $actorUserId,
            $data,
            $caseId,
            $capability,
            $employmentId,
            $effectiveFrom,
            $effectiveTo,
            $baseSalary,
            $parameterOverrides,
            $status,
            $scheme,
            $contentHash,
        ): EmployeeCompensation {
            $existing = EmployeeCompensation::query()
                ->where('employment_id', $employmentId)
                ->where('effective_from', $effectiveFrom->value())
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw new ConflictException('A compensation version already exists for that effective date.', [
                    'reason' => 'COMPENSATION_EFFECTIVE_DATE_EXISTS',
                ]);
            }

            $previousId = $data['supersedes_id']
                ?? EmployeeCompensation::query()
                    ->where('employment_id', $employmentId)
                    ->where('effective_from', '<', $effectiveFrom->value())
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id')
                    ->value('id');

            $compensation = EmployeeCompensation::query()->create([
                'employment_id' => $employmentId,
                'contract_version_id' => $data['contract_version_id'] ?? null,
                'effective_from' => $effectiveFrom->value(),
                'effective_to' => $effectiveTo?->value(),
                'status' => $status,
                'currency' => $baseSalary->currency(),
                'base_salary_cents' => $baseSalary->minor(),
                'salary_scheme_code' => $data['salary_scheme_code'] ?? $scheme?->code,
                'payroll_scheme_id' => $data['payroll_scheme_id'] ?? null,
                'payroll_scheme_version' => $scheme?->version,
                'parameter_overrides_json' => $parameterOverrides === [] ? null : $parameterOverrides,
                'approved_case_id' => $data['approved_case_id'] ?? $caseId,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'content_hash' => $contentHash,
                'supersedes_id' => $previousId !== null ? (int) $previousId : null,
                'created_by' => $actorUserId,
                'created_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.compensation.versioned',
                aggregateType: 'employee_compensation',
                aggregateId: (int) $compensation->id,
                after: [
                    'employment_id' => $employmentId,
                    'contract_version_id' => $data['contract_version_id'] ?? null,
                    'effective_from' => $effectiveFrom->value(),
                    'currency' => $baseSalary->currency(),
                    'base_salary_cents' => $baseSalary->minor(),
                    'payroll_scheme_id' => $data['payroll_scheme_id'] ?? null,
                    'payroll_scheme_version' => $scheme?->version,
                    'content_hash' => $contentHash,
                    'case_id' => $caseId,
                ],
            );

            // ADR-0024: writing a P3 amount is itself sensitive access and
            // must leave field-name evidence (never the amount value).
            $this->sensitiveAuditor->record(
                userId: $actorUserId,
                capabilityCode: $capability,
                resourceType: 'employee_compensation',
                resourceId: (int) $compensation->id,
                fields: ['base_salary_cents'],
                context: [
                    'access' => 'WRITE',
                    'employment_id' => $employmentId,
                    'effective_from' => $effectiveFrom->value(),
                    'case_id' => $caseId,
                ],
            );

            return $compensation->refresh();
        });
    }

    /**
     * A salary adjustment must cite an approved salary-change workflow case.
     *
     * @param  array{employment_id: int, effective_from: string, base_salary_cents: int, currency?: string, reason: string, case_id: int, salary_scheme_code?: string|null}  $data
     * @return array{adjustment: SalaryAdjustment, compensation: EmployeeCompensation}
     */
    public function createAdjustment(int $actorUserId, array $data): array
    {
        $caseId = (int) $data['case_id'];
        $case = $this->workflow->caseFor($caseId);

        if ($case === null) {
            throw new NotFoundOrForbiddenException;
        }

        $employmentId = (int) $data['employment_id'];
        $this->assertApprovedSalaryCase($case, $employmentId, (string) $data['effective_from'], (int) $data['base_salary_cents']);

        return DB::transaction(function () use ($actorUserId, $data, $case, $employmentId): array {
            $alreadyApplied = SalaryAdjustment::query()
                ->where('case_id', (int) $case->id)
                ->lockForUpdate()
                ->exists();

            if ($alreadyApplied) {
                throw new ConflictException('This salary-change case already has an adjustment.', [
                    'reason' => 'SALARY_ADJUSTMENT_CASE_ALREADY_APPLIED',
                ]);
            }

            $compensation = $this->createCompensation($actorUserId, [
                'employment_id' => $employmentId,
                'effective_from' => (string) $data['effective_from'],
                'base_salary_cents' => (int) $data['base_salary_cents'],
                'currency' => (string) ($data['currency'] ?? 'CNY'),
                'salary_scheme_code' => $data['salary_scheme_code'] ?? $this->salarySchemeFor($employmentId, (string) $data['effective_from']),
                'contract_version_id' => $data['contract_version_id'] ?? null,
                'payroll_scheme_id' => $data['payroll_scheme_id'] ?? null,
                'parameter_overrides' => $data['parameter_overrides'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'approved_case_id' => (int) $case->id,
                'source_type' => 'SALARY_ADJUSTMENT',
                'source_id' => (int) $case->id,
            ], (int) $case->id, 'payroll.salary-adjustment.manage');

            $adjustment = SalaryAdjustment::query()->create([
                'employment_id' => $employmentId,
                'effective_from' => (string) $compensation->effective_from,
                'currency' => (string) $compensation->currency,
                'base_salary_cents' => (int) $compensation->base_salary_cents,
                'reason' => (string) $data['reason'],
                'case_id' => (int) $case->id,
                'compensation_id' => (int) $compensation->id,
                'created_by' => $actorUserId,
                'created_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.salary_adjustment.created',
                aggregateType: 'salary_adjustment',
                aggregateId: (int) $adjustment->id,
                after: [
                    'employment_id' => $employmentId,
                    'case_id' => (int) $case->id,
                    'compensation_id' => (int) $compensation->id,
                    'effective_from' => (string) $adjustment->effective_from,
                    'base_salary_cents' => (int) $adjustment->base_salary_cents,
                ],
            );

            $this->sensitiveAuditor->record(
                userId: $actorUserId,
                capabilityCode: 'payroll.salary-adjustment.manage',
                resourceType: 'salary_adjustment',
                resourceId: (int) $adjustment->id,
                fields: ['base_salary_cents'],
                context: [
                    'access' => 'WRITE',
                    'employment_id' => $employmentId,
                    'case_id' => (int) $case->id,
                    'effective_from' => (string) $adjustment->effective_from,
                ],
            );

            return ['adjustment' => $adjustment->refresh(), 'compensation' => $compensation];
        });
    }

    /**
     * Parameter overrides are a flat map of approved scalar values. Nested
     * objects, floats and list-shaped payloads are rejected so no arbitrary
     * structure can enter a payroll snapshot.
     *
     * @return array<string, string|int|bool|null>
     */
    private function normalizeParameterOverrides(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new ValidationException(['parameter_overrides' => ['Parameter overrides must be a flat object.']]);
        }

        $normalized = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/i', $key) !== 1) {
                throw new ValidationException(['parameter_overrides' => ['Every parameter override needs a stable code key.']]);
            }

            if ($entry !== null && ! is_string($entry) && ! is_int($entry) && ! is_bool($entry)) {
                throw new ValidationException([
                    "parameter_overrides.{$key}" => ['Only scalar strings, integers, booleans or null are allowed.'],
                ]);
            }

            $normalized[$key] = $entry;
        }

        ksort($normalized);

        return $normalized;
    }

    private function salarySchemeFor(int $employmentId, string $effectiveOn): ?string
    {
        return $this->terms->termsAsOf($employmentId, $effectiveOn)?->salarySchemeCode;
    }

    private function assertApprovedSalaryCase(WorkflowCaseRecord $case, int $employmentId, string $effectiveFrom, int $baseSalaryCents): void
    {
        if ($case->applicationStatus !== 'APPROVED') {
            throw new ConflictException('Salary adjustments require an approved workflow case.', [
                'reason' => 'SALARY_ADJUSTMENT_CASE_NOT_APPROVED',
                'case_status' => $case->applicationStatus,
            ]);
        }

        $type = strtoupper($case->type);

        if (! in_array($type, ['TERMS_CHANGE', 'SALARY_CHANGE', 'SALARY_ADJUSTMENT'], true)) {
            throw new ConflictException('The workflow case is not a salary-change case.', [
                'reason' => 'SALARY_ADJUSTMENT_CASE_TYPE_INVALID',
                'case_type' => $case->type,
            ]);
        }

        if ($case->subjectType === 'employment' && $case->subjectId !== null && $case->subjectId !== $employmentId) {
            throw new ConflictException('The workflow case belongs to another employment.', [
                'reason' => 'SALARY_ADJUSTMENT_CASE_EMPLOYMENT_MISMATCH',
            ]);
        }

        $payload = $case->payload;

        if (isset($payload['employment_id']) && (int) $payload['employment_id'] !== $employmentId) {
            throw new ConflictException('The workflow case belongs to another employment.', [
                'reason' => 'SALARY_ADJUSTMENT_CASE_EMPLOYMENT_MISMATCH',
            ]);
        }

        $approvedDate = $payload['effective_date'] ?? $payload['effective_from'] ?? null;

        if (is_string($approvedDate) && BusinessDate::tryFrom($approvedDate) !== null && $approvedDate !== $effectiveFrom) {
            throw new ConflictException('The adjustment effective date differs from the approved case.', [
                'reason' => 'SALARY_ADJUSTMENT_EFFECTIVE_DATE_MISMATCH',
            ]);
        }

        $approvedAmount = $payload['base_salary_cents'] ?? $payload['base_salary_minor'] ?? null;

        if ($approvedAmount !== null && (int) $approvedAmount !== $baseSalaryCents) {
            throw new ConflictException('The adjustment amount differs from the approved case.', [
                'reason' => 'SALARY_ADJUSTMENT_AMOUNT_MISMATCH',
            ]);
        }
    }
}
