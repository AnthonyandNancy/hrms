<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\Engagement\Contracts\NoticeWorkspaceDirectory;
use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\Payroll\Contracts\PayslipWorkspaceDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\PeopleWorkspaceDirectory;
use App\Modules\Time\Contracts\AttendanceWorkspaceReader;
use App\Modules\Time\Contracts\LeaveBalanceReader;
use App\Modules\Time\Contracts\LeaveRequestReader;
use App\Modules\Time\Contracts\LegalEntityCalendarDirectory;
use App\Shared\ApiResponse;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Carbon\CarbonImmutable;
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
        private readonly PeopleWorkspaceDirectory $profiles,
        private readonly LeaveBalanceReader $leaveBalances,
        private readonly LeaveRequestReader $leaveRequests,
        private readonly AttendanceWorkspaceReader $attendance,
        private readonly LegalEntityCalendarDirectory $calendars,
        private readonly PayslipWorkspaceDirectory $payslips,
        private readonly NoticeWorkspaceDirectory $notices,
        private readonly CompanyBusinessDate $businessDates,
        private readonly Clock $clock,
    ) {}

    public function show(): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $asOf = $this->businessDates->today()->value();
        $employmentId = $principal->employmentId()
            ?? $this->people->employmentForUser($principal->userId());

        if ($employmentId === null) {
            return ApiResponse::ok($this->emptyProjection());
        }

        $profile = $this->profiles->profile($employmentId, $asOf);

        if ($profile === null) {
            return ApiResponse::ok($this->emptyProjection());
        }

        $month = $this->clock->now()
            ->setTimezone($this->calendars->timezone()
                ?? (string) config('hrms.default_timezone', 'Asia/Shanghai'))
            ->format('Y-m');
        $attendance = $this->attendance->currentMonth($employmentId, $month);

        return ApiResponse::ok([
            'employment_linked' => true,
            'profile' => [
                'employmentId' => $profile->employmentId,
                'employeeNo' => $profile->employeeNo,
                'name' => $profile->personName,
                'initials' => $profile->initials,
                'jobTitle' => $profile->jobTitle,
                'orgName' => $profile->orgName,
                'managerName' => $profile->managerName,
                'workLocation' => $profile->workLocation,
                'email' => $profile->email,
                'phone' => ['display' => null, 'masked' => true, 'profile' => 'P1_INTERNAL', 'revealable' => false, 'exportable' => false],
            ],
            'contract' => $profile->termsVersion !== null ? [
                'contractNo' => 'TERMS-'.$profile->employeeNo.'-V'.$profile->termsVersion,
                'type' => $profile->employmentType,
                'startDate' => $profile->termsEffectiveFrom ?? $profile->validFrom,
                'endDate' => $this->finiteDate($profile->termsEffectiveTo),
                'daysToExpiry' => $this->daysToExpiry($profile->termsEffectiveTo, $asOf),
                'status' => $this->contractStatus($profile->termsEffectiveTo, $asOf),
            ] : null,
            'businessDate' => $asOf,
            'attendanceSummary' => $attendance['summary'],
            'attendanceMonth' => $attendance['month'],
            'attendanceException' => $attendance['latestException'],
            'leaveBalances' => $this->leaveBalances->outstanding($employmentId, $asOf),
            'myRequests' => $this->leaveRequests->forEmployment($employmentId, $asOf),
            'payslips' => $this->payslips->recentForEmployment($employmentId),
            'notices' => $this->notices->forUser($principal->userId()),
            'freshness' => $this->clock->now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProjection(): array
    {
        return [
            'employment_linked' => false,
            'profile' => [
                'employmentId' => null,
                'employeeNo' => '',
                'name' => '',
                'initials' => '—',
                'jobTitle' => null,
                'orgName' => null,
                'managerName' => null,
                'workLocation' => null,
                'email' => null,
                'phone' => ['display' => null, 'masked' => true, 'profile' => 'P1_INTERNAL', 'revealable' => false, 'exportable' => false],
            ],
            'contract' => null,
            'businessDate' => $this->businessDates->today()->value(),
            'attendanceSummary' => $this->emptyAttendanceSummary(),
            'attendanceMonth' => $this->clock->now()->format('Y-m'),
            'attendanceException' => null,
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

    private function finiteDate(?string $date): ?string
    {
        return $date !== null && $date !== '' && $date < '9999-01-01' ? $date : null;
    }

    private function daysToExpiry(?string $date, string $asOf): ?int
    {
        $finite = $this->finiteDate($date);

        if ($finite === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($asOf, 'UTC')->diffInDays(CarbonImmutable::parse($finite, 'UTC'), false);
    }

    private function contractStatus(?string $date, string $asOf): string
    {
        $days = $this->daysToExpiry($date, $asOf);

        if ($days === null) {
            return 'ACTIVE';
        }

        return $days <= 90 ? 'EXPIRING' : 'ACTIVE';
    }
}
