<?php

declare(strict_types=1);

namespace App\Modules\Platform\Retention;

/**
 * Code-owned retention catalogue. Periods are minimums from the approved plan
 * §6.5; they are never treated as maximums. A legal hold always wins over an
 * expiry date. Tables are listed only when this reference implementation can
 * demonstrate a safe purge predicate against an existing schema; classes for
 * tables owned by parallel modules are declared but fail closed until the table
 * exists.
 */
final class RetentionClass
{
    /**
     * @return array<string, array{label: string, table: string, timestamp_column: string, period_months: int|null, permanent: bool, owner: string}>
     */
    public static function definitions(): array
    {
        return [
            'payroll_payment_records' => [
                'label' => 'Payroll payment records',
                'table' => 'payslips',
                'timestamp_column' => 'published_at',
                'period_months' => 24,
                'permanent' => false,
                'owner' => 'Payroll',
            ],
            'accounting_vouchers' => [
                'label' => 'Accounting vouchers and ledgers',
                'table' => 'payroll_runs',
                'timestamp_column' => 'closed_at',
                'period_months' => 360,
                'permanent' => false,
                'owner' => 'Payroll',
            ],
            'employment_contracts' => [
                'label' => 'Employment contracts',
                'table' => 'employment_terms',
                'timestamp_column' => 'recorded_at',
                'period_months' => 24,
                'permanent' => false,
                'owner' => 'People',
            ],
            'security_audit_logs' => [
                'label' => 'Security and audit logs',
                'table' => 'audit_events',
                'timestamp_column' => 'occurred_at',
                'period_months' => 36,
                'permanent' => false,
                'owner' => 'Platform',
            ],
            'annual_financial_reports' => [
                'label' => 'Annual financial reports',
                'table' => 'report_runs',
                'timestamp_column' => 'created_at',
                'period_months' => null,
                'permanent' => true,
                'owner' => 'Reporting',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array{label: string, table: string, timestamp_column: string, period_months: int|null, permanent: bool, owner: string}
     */
    public static function get(string $class): array
    {
        $definitions = self::definitions();

        if (! isset($definitions[$class])) {
            throw new \InvalidArgumentException("Unknown retention class '{$class}'. Known: ".implode(', ', array_keys($definitions)));
        }

        return $definitions[$class];
    }
}
