<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Lifecycle\Contracts\TurnoverDirectory;
use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\WorkforceReportingDirectory;
use App\Modules\Reporting\Models\ProjectionRow;
use App\Modules\Talent\Contracts\RecruitmentFunnelDirectory;
use App\Modules\Time\Contracts\LeaveLiabilityDirectory;
use App\Modules\Workflow\Contracts\PendingApprovalRow;
use App\Modules\Workflow\Contracts\ReportingApprovalDirectory;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds Reporting's own projection table exclusively from module Contracts.
 * The projection is disposable: dropping it and replaying this service for a
 * business date yields the same rows and source hashes.
 *
 * Payroll and recruitment rows are published only from their owning module's
 * Contract. Reporting never reads payroll_runs or candidates directly and
 * never writes either module's tables.
 */
final class ProjectionBuilder
{
    public function __construct(
        private readonly WorkforceReportingDirectory $workforce,
        private readonly ReportingApprovalDirectory $approvals,
        private readonly LeaveLiabilityDirectory $leave,
        private readonly PayrollCostDirectory $payroll,
        private readonly RecruitmentFunnelDirectory $recruitment,
        private readonly TurnoverDirectory $turnover,
        private readonly PeopleDirectory $people,
        private readonly Clock $clock,
    ) {}

    /**
     * Build the projection only when this as-of snapshot is absent.
     * A dashboard snapshot is always rebuildable via rebuild().
     */
    public function ensure(string $asOf): void
    {
        $exists = ProjectionRow::query()
            ->where('business_date', $asOf)
            ->exists();
        $payrollMissing = $this->payroll->available() && ! ProjectionRow::query()
            ->where('business_date', $asOf)
            ->where('source_projection', 'payroll_cost')
            ->where('entity_type', 'SOURCE_SYNC')
            ->where('status', 'AVAILABLE')
            ->exists();
        $recruitmentMissing = $this->recruitment->available() && ! ProjectionRow::query()
            ->where('business_date', $asOf)
            ->where('source_projection', 'recruitment_funnel')
            ->where('entity_type', 'SOURCE_SYNC')
            ->where('status', 'AVAILABLE')
            ->exists();

        if (! $exists || $payrollMissing || $recruitmentMissing) {
            $this->rebuild($asOf);
        }
    }

    public function rebuild(string $asOf): void
    {
        $workforce = $this->workforce->rows($asOf);
        $approvals = $this->approvals->pendingApprovals();
        $leave = $this->leave->balances($asOf);
        $now = $this->clock->now();

        DB::transaction(function () use ($asOf, $workforce, $approvals, $leave, $now): void {
            ProjectionRow::query()
                ->where('business_date', $asOf)
                ->delete();

            foreach ($workforce as $row) {
                $employee = $row->displayName ?? $row->employeeNo;
                $this->store($asOf, 'workforce', 'headcount', 'EMPLOYMENT', $row->employmentId, [
                    'employment_id' => $row->employmentId,
                    'org_unit_id' => $row->orgUnitId,
                    'label' => $employee,
                    'secondary_label' => trim(($row->orgName ?? '').' · '.$row->employeeNo, ' ·'),
                    'status' => $row->status,
                    'numeric_value' => 1,
                    'attributes_json' => [
                        'employee' => $employee,
                        'employee_no' => $row->employeeNo,
                        'org_path' => $row->orgName,
                        'status' => $row->status,
                        'probation_end_date' => $row->probationEndDate,
                        'contract_end_date' => $row->contractEndDate,
                    ],
                ], $now);

                if ($row->status === 'PROBATION') {
                    $this->store($asOf, 'workforce', 'probation', 'EMPLOYMENT', $row->employmentId, [
                        'employment_id' => $row->employmentId,
                        'org_unit_id' => $row->orgUnitId,
                        'label' => $employee,
                        'secondary_label' => trim(($row->orgName ?? '').' · '.$row->employeeNo, ' ·'),
                        'status' => 'PROBATION',
                        'numeric_value' => 1,
                        'attributes_json' => [
                            'employee' => $employee,
                            'employee_no' => $row->employeeNo,
                            'org_path' => $row->orgName,
                            'probation_end_date' => $row->probationEndDate,
                            'hire_date' => null,
                            'status' => 'PROBATION',
                        ],
                    ], $now);
                }

                if ($row->contractEndDate !== null && $row->contractEndDate >= $asOf) {
                    $daysRemaining = (int) CarbonImmutable::parse($asOf, 'UTC')
                        ->diffInDays(CarbonImmutable::parse($row->contractEndDate, 'UTC'), false);

                    if ($daysRemaining >= 0 && $daysRemaining <= 90) {
                        $this->store($asOf, 'workforce', 'contract_expiring', 'CONTRACT', $row->employmentId, [
                            'employment_id' => $row->employmentId,
                            'org_unit_id' => $row->orgUnitId,
                            'label' => $employee,
                            'secondary_label' => trim(($row->orgName ?? '').' · '.$row->employeeNo, ' ·'),
                            'status' => 'EXPIRING',
                            'numeric_value' => $daysRemaining,
                            'attributes_json' => [
                                'employee' => $employee,
                                'org_path' => $row->orgName,
                                'end_date' => $row->contractEndDate,
                                'days_remaining' => $daysRemaining,
                                'status' => 'EXPIRING',
                            ],
                        ], $now);
                    }
                }
            }

            foreach ($approvals as $approval) {
                /** @var PendingApprovalRow $approval */
                $age = max(0, (int) CarbonImmutable::parse($approval->businessDate, 'UTC')
                    ->diffInDays(CarbonImmutable::parse($asOf, 'UTC'), false));
                $orgId = null;

                if ($approval->employmentId !== null) {
                    $snapshot = $this->people->employmentSnapshot($approval->employmentId, $asOf);
                    $orgId = $snapshot?->orgUnitId;
                }

                $this->store($asOf, 'pending_approvals', 'pending_approvals', 'CASE', $approval->caseId, [
                    'employment_id' => $approval->employmentId,
                    'org_unit_id' => $orgId,
                    'label' => $approval->title,
                    'secondary_label' => $approval->caseNo,
                    'status' => $approval->taskStatus,
                    'numeric_value' => $age,
                    'attributes_json' => [
                        'case_no' => $approval->caseNo,
                        'title' => $approval->title,
                        'status' => $approval->taskStatus,
                        'age_days' => $age,
                        'node_key' => $approval->nodeKey,
                        'due_at' => $approval->dueAt,
                    ],
                ], $now);
            }

            foreach ($leave as $balance) {
                $snapshot = $this->people->employmentSnapshot($balance->employmentId, $asOf);
                $employeeNumber = $snapshot !== null ? $snapshot->employeeNo : (string) $balance->employmentId;

                $this->store($asOf, 'leave_liability', 'leave_liability', 'LEAVE_BALANCE', $balance->employmentId.':'.$balance->leaveTypeId, [
                    'employment_id' => $balance->employmentId,
                    'org_unit_id' => $snapshot?->orgUnitId,
                    'label' => $balance->leaveTypeName,
                    'secondary_label' => $employeeNumber,
                    'status' => $balance->leaveTypeCode,
                    'numeric_value' => $balance->balanceMinutes,
                    'attributes_json' => [
                        'employee' => null,
                        'employee_no' => $employeeNumber,
                        'leave_type' => $balance->leaveTypeName,
                        'leave_type_code' => $balance->leaveTypeCode,
                        'balance_minutes' => $balance->balanceMinutes,
                    ],
                ], $now);
            }

            if ($this->payroll->available()) {
                foreach ($this->payroll->costByEntity($asOf) as $index => $cost) {
                    $currency = strtoupper($cost->currencyCode);
                    $this->store($asOf, 'payroll_cost', 'payroll_cost_by_entity', 'PAYROLL_COST', 'published:'.$asOf.':'.$currency.':'.$index, [
                        'employment_id' => null,
                        'org_unit_id' => null,
                        'label' => '已发布工资批次（'.$asOf.'）',
                        'secondary_label' => $currency.' · 雇主成本',
                        'status' => 'PUBLISHED',
                        'numeric_value' => $cost->employerCostCents,
                        'attributes_json' => [
                            'gross_cents' => $cost->grossCents,
                            'net_cents' => $cost->netCents,
                            'employer_cost_cents' => $cost->employerCostCents,
                            'currency_code' => $currency,
                        ],
                    ], $now);
                }
            }

            $this->store($asOf, 'payroll_cost', 'source_sync', 'SOURCE_SYNC', 'payroll_cost', [
                'employment_id' => null,
                'org_unit_id' => null,
                'label' => '薪酬成本投影同步标记',
                'secondary_label' => null,
                'status' => $this->payroll->available() ? 'AVAILABLE' : 'UNAVAILABLE',
                'numeric_value' => 0,
                'attributes_json' => ['source' => 'payroll_cost', 'available' => $this->payroll->available()],
            ], $now);

            if ($this->recruitment->available()) {
                foreach ($this->recruitment->funnel($asOf) as $stage) {
                    $this->store($asOf, 'recruitment_funnel', 'recruitment_funnel', 'RECRUITMENT_STAGE', $stage->stage.':'.($stage->orgUnitId ?? 'company'), [
                        'employment_id' => null,
                        'org_unit_id' => $stage->orgUnitId,
                        'label' => MetricDefinition::stageLabel($stage->stage),
                        'secondary_label' => $stage->orgUnitId === null ? '全公司' : '授权组织',
                        'status' => $stage->stage,
                        'numeric_value' => $stage->count,
                        'attributes_json' => [
                            'stage' => $stage->stage,
                            'stage_label' => MetricDefinition::stageLabel($stage->stage),
                            'count' => $stage->count,
                            'org_unit_id' => $stage->orgUnitId,
                        ],
                    ], $now);
                }
            }

            $this->store($asOf, 'recruitment_funnel', 'source_sync', 'SOURCE_SYNC', 'recruitment_funnel', [
                'employment_id' => null,
                'org_unit_id' => null,
                'label' => '招聘漏斗投影同步标记',
                'secondary_label' => null,
                'status' => $this->recruitment->available() ? 'AVAILABLE' : 'UNAVAILABLE',
                'numeric_value' => 0,
                'attributes_json' => ['source' => 'recruitment_funnel', 'available' => $this->recruitment->available()],
            ], $now);
        });
    }

    public function payrollAvailable(): bool
    {
        return $this->payroll->available();
    }

    public function payrollReason(): ?string
    {
        return $this->payroll->unavailableReason();
    }

    public function recruitmentAvailable(): bool
    {
        return $this->recruitment->available();
    }

    public function recruitmentReason(): ?string
    {
        $reason = $this->recruitment->unavailableReason();

        return $reason !== '' ? $reason : null;
    }

    public function turnoverAvailable(): bool
    {
        return $this->turnover->available();
    }

    public function turnoverReason(): ?string
    {
        return $this->turnover->unavailableReason();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(
        string $asOf,
        string $source,
        string $metric,
        string $entityType,
        int|string $entityId,
        array $attributes,
        CarbonImmutable $now,
    ): void {
        $payload = [
            'label' => (string) ($attributes['label'] ?? ''),
            'secondary_label' => $attributes['secondary_label'] ?? null,
            'status' => $attributes['status'] ?? null,
            'numeric_value' => (int) ($attributes['numeric_value'] ?? 0),
            'attributes_json' => (array) ($attributes['attributes_json'] ?? []),
        ];

        ProjectionRow::query()->updateOrCreate(
            [
                'source_projection' => $source,
                'business_date' => $asOf,
                'entity_type' => $entityType,
                'entity_id' => (string) $entityId,
            ],
            [
                'metric_code' => $metric,
                'employment_id' => $attributes['employment_id'] ?? null,
                'org_unit_id' => $attributes['org_unit_id'] ?? null,
                'label' => $payload['label'],
                'secondary_label' => $payload['secondary_label'],
                'status' => $payload['status'],
                'numeric_value' => $payload['numeric_value'],
                'attributes_json' => $payload['attributes_json'],
                'source_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'computed_at' => $now,
            ],
        );
    }
}
