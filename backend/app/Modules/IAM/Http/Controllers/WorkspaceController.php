<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Time\Contracts\LeaveBalanceReader;
use App\Modules\Time\Contracts\LeaveRequestReader;
use App\Shared\ApiResponse;
use App\Shared\Time\Clock;
use App\Shared\Time\TenantBusinessDate;
use Illuminate\Http\JsonResponse;

/**
 * Self-service workspace projection. It composes other modules only through
 * their Contracts and always scopes to the authenticated principal's own
 * employment; it is not an HR-wide read surface.
 */
final class WorkspaceController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly PeopleDirectory $people,
        private readonly OrganizationDirectory $organization,
        private readonly LeaveBalanceReader $leaveBalances,
        private readonly LeaveRequestReader $leaveRequests,
        private readonly TenantBusinessDate $businessDates,
        private readonly Clock $clock,
    ) {}

    public function show(): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $tenantId = $principal->tenantId();
        $asOf = $this->businessDates->today($tenantId)->value();
        $employmentId = $principal->employmentId()
            ?? $this->people->employmentForUser($tenantId, $principal->userId());

        if ($employmentId === null) {
            return ApiResponse::ok($this->emptyProjection($principal->userId()));
        }

        $snapshot = $this->people->employmentSnapshot($tenantId, $employmentId, $asOf);

        if ($snapshot === null) {
            return ApiResponse::ok($this->emptyProjection($principal->userId()));
        }

        $orgUnitName = null;

        if ($snapshot->orgUnitId !== null) {
            foreach ($this->organization->tree($tenantId, $snapshot->legalEntityId, $asOf) as $unit) {
                if ((int) $unit['id'] === $snapshot->orgUnitId) {
                    $orgUnitName = (string) $unit['name'];
                    break;
                }
            }
        }

        $balances = $this->leaveBalances->outstanding($tenantId, $employmentId, $asOf);

        return ApiResponse::ok([
            'profile' => [
                'employmentId' => $snapshot->employmentId,
                'employeeNo' => $snapshot->employeeNo,
                'name' => $snapshot->employeeNo,
                'initials' => $this->initials($snapshot->employeeNo),
                'jobTitle' => null,
                'orgName' => $orgUnitName,
                'managerName' => null,
                'workLocation' => null,
                'email' => null,
                'phone' => ['display' => null, 'masked' => true, 'profile' => 'P1_INTERNAL', 'revealable' => false, 'exportable' => false],
            ],
            'contract' => null,
            'attendanceSummary' => $this->emptyAttendanceSummary(),
            'attendanceMonth' => $this->clock->now()->format('Y-m'),
            'leaveBalances' => $balances,
            'myRequests' => $this->leaveRequests->forEmployment($tenantId, $employmentId, $asOf),
            'payslips' => [],
            'notices' => [],
            'freshness' => $this->clock->now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProjection(int $userId): array
    {
        return [
            'profile' => [
                'employmentId' => null,
                'employeeNo' => '',
                'name' => 'User '.$userId,
                'initials' => 'U',
                'jobTitle' => null,
                'orgName' => null,
                'managerName' => null,
                'workLocation' => null,
                'email' => null,
                'phone' => ['display' => null, 'masked' => true, 'profile' => 'P1_INTERNAL', 'revealable' => false, 'exportable' => false],
            ],
            'contract' => null,
            'attendanceSummary' => $this->emptyAttendanceSummary(),
            'attendanceMonth' => $this->clock->now()->format('Y-m'),
            'leaveBalances' => [],
            'myRequests' => [],
            'payslips' => [],
            'notices' => [],
            'freshness' => $this->clock->now()->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{expectedDays: int, presentDays: int, lateCount: int, absentCount: int, leaveDays: int, remoteDays: int}
     */
    private function emptyAttendanceSummary(): array
    {
        return [
            'expectedDays' => 0,
            'presentDays' => 0,
            'lateCount' => 0,
            'absentCount' => 0,
            'leaveDays' => 0,
            'remoteDays' => 0,
        ];
    }

    private function initials(string $value): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? '—' : mb_strtoupper(mb_substr($trimmed, 0, 2));
    }
}
