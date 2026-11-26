<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Enums;

/**
 * Ordered cutover waves. The enum order is the only supported apply order.
 */
enum MigrationDomain: string
{
    case ORGANIZATIONS = 'organizations';
    case PEOPLE = 'people';
    case ASSIGNMENTS = 'assignments';
    case TERMS = 'terms';
    case LEAVE_OPENINGS = 'leave_openings';
    case PAYROLL_YTD = 'payroll_ytd';

    public function label(): string
    {
        return match ($this) {
            self::ORGANIZATIONS => 'Organizations',
            self::PEOPLE => 'People and employments',
            self::ASSIGNMENTS => 'Assignments',
            self::TERMS => 'Contracts and terms',
            self::LEAVE_OPENINGS => 'Leave opening balances',
            self::PAYROLL_YTD => 'Payroll year-to-date',
        };
    }

    /**
     * Dependencies enforced before validation and apply. A domain may only be
     * applied when the earlier domains have a successful instance-wide run.
     *
     * @return list<self>
     */
    public function dependsOn(): array
    {
        return match ($this) {
            self::ORGANIZATIONS => [],
            self::PEOPLE => [self::ORGANIZATIONS],
            self::ASSIGNMENTS => [self::PEOPLE],
            self::TERMS => [self::PEOPLE],
            self::LEAVE_OPENINGS => [self::PEOPLE],
            self::PAYROLL_YTD => [self::PEOPLE],
        };
    }

    /**
     * Natural source key column documented in the import contract.
     */
    public function sourceCountKey(): string
    {
        return match ($this) {
            self::ORGANIZATIONS => 'code',
            self::PEOPLE => 'employee_no',
            self::ASSIGNMENTS => 'employment_no',
            self::TERMS => 'employment_no',
            self::LEAVE_OPENINGS => 'employment_no',
            self::PAYROLL_YTD => 'employment_no',
        };
    }

    /**
     * Integer sum columns reconciled source-versus-target.
     *
     * @return list<string>
     */
    public function reconciliationSums(): array
    {
        return match ($this) {
            self::ORGANIZATIONS, self::PEOPLE, self::ASSIGNMENTS, self::TERMS => [],
            self::LEAVE_OPENINGS => ['quantity_minutes'],
            self::PAYROLL_YTD => [
                'taxable_cents',
                'tax_withheld_cents',
                'si_cents',
                'hf_cents',
                'gross_cents',
                'net_cents',
            ],
        };
    }

    public function cutoverWave(): int
    {
        return match ($this) {
            self::ORGANIZATIONS, self::PEOPLE, self::ASSIGNMENTS, self::TERMS => 1,
            self::LEAVE_OPENINGS => 2,
            self::PAYROLL_YTD => 3,
        };
    }
}
