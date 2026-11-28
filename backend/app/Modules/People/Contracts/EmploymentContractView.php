<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use App\Modules\People\Models\Person;
use App\Shared\Time\BusinessDate;
use Carbon\CarbonImmutable;

/**
 * Read model for one employment contract. It only reads stored rows (plus the
 * company-business-date derived display status); it never synthesises a
 * contract, version or employee identity.
 */
final readonly class EmploymentContractView
{
    private const OPERATIONAL = ['ACTIVE', 'EXPIRING'];

    /**
     * @return array<string, mixed>
     */
    public static function toArray(
        EmploymentContract $contract,
        string $asOf,
        ?Employment $employment = null,
        ?Person $person = null,
    ): array {
        $endDate = $contract->end_date !== null ? (string) $contract->end_date : null;
        // Carbon 3's diffInDays() returns a float. Convert to int only after
        // the calendar comparison has been rounded to whole days so the DTO
        // never exposes a fractional days_to_expiry value.
        $days = $endDate !== null
            ? (int) round(CarbonImmutable::parse($asOf, 'UTC')
                ->diffInDays(CarbonImmutable::parse($endDate, 'UTC'), false))
            : null;
        $displayStatus = self::displayStatus((string) $contract->status, $endDate, $days);

        return [
            'id' => (int) $contract->id,
            'employment_id' => (int) $contract->employment_id,
            'employee_no' => $employment !== null ? (string) $employment->employee_no : null,
            'employee_name' => $person !== null ? (string) $person->legal_name : null,
            'contract_no' => (string) $contract->contract_no,
            'contract_type' => (string) $contract->contract_type,
            'start_date' => (string) $contract->start_date,
            'end_date' => $endDate,
            'signed_on' => $contract->signed_on !== null ? (string) $contract->signed_on : null,
            'terminated_on' => $contract->terminated_on !== null ? (string) $contract->terminated_on : null,
            'status' => (string) $contract->status,
            'display_status' => $displayStatus,
            'display_status_label' => self::displayStatusLabel($displayStatus),
            'version' => (int) $contract->version,
            'previous_contract_id' => $contract->previous_contract_id !== null ? (int) $contract->previous_contract_id : null,
            'renewed_by_id' => $contract->renewed_by_id !== null ? (int) $contract->renewed_by_id : null,
            'template_id' => $contract->template_id !== null ? (int) $contract->template_id : null,
            'days_to_expiry' => $days,
            'renewal_count' => max(0, (int) $contract->version - 1),
            'terms_summary' => $contract->terms_summary,
            'work_location' => $contract->work_location,
            'probation_end_date' => $contract->probation_end_date !== null ? (string) $contract->probation_end_date : null,
        ];
    }

    public static function displayStatus(string $storedStatus, ?string $endDate, ?int $daysToExpiry): string
    {
        $status = strtoupper($storedStatus);

        if ($status === 'DRAFT') {
            return 'DRAFT';
        }

        if ($status === 'PENDING_EFFECTIVE') {
            return 'PENDING_EFFECTIVE';
        }

        if ($status === 'TERMINATED') {
            return 'TERMINATED';
        }

        if ($status === 'RENEWED') {
            return 'RENEWED';
        }

        if ($status === 'ARCHIVED') {
            return 'ARCHIVED';
        }

        if (! in_array($status, self::OPERATIONAL, true)) {
            return 'UNKNOWN';
        }

        if ($endDate === null) {
            return 'EFFECTIVE';
        }

        if ($daysToExpiry !== null && $daysToExpiry < 0) {
            return 'EXPIRED';
        }

        if ($status === 'EXPIRING'
            || ($daysToExpiry !== null && $daysToExpiry <= (int) config('hrms.contract_expiry_window_days', EmploymentContract::EXPIRY_WINDOW_DAYS))) {
            return 'EXPIRING';
        }

        return 'EFFECTIVE';
    }

    public static function displayStatusLabel(string $displayStatus): string
    {
        return match ($displayStatus) {
            'DRAFT' => '草稿',
            'PENDING_EFFECTIVE' => '待生效',
            'EFFECTIVE' => '生效中',
            'EXPIRING' => '即将到期',
            'EXPIRED' => '已到期',
            'TERMINATED' => '已终止',
            'RENEWED' => '已续签',
            'ARCHIVED' => '已归档',
            default => '未知状态',
        };
    }

    public static function isExpired(EmploymentContract $contract, string $asOf): bool
    {
        if (! in_array((string) $contract->status, self::OPERATIONAL, true)) {
            return false;
        }

        return $contract->end_date !== null
            && BusinessDate::from((string) $contract->end_date)->isBefore(BusinessDate::from($asOf));
    }
}
