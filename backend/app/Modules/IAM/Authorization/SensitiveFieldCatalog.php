<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\FieldProfile;

/**
 * Code-owned sensitive field schemas. Field policy writes can only select a
 * registered key and can never raise its declared profile (P3 stays P3).
 */
final class SensitiveFieldCatalog
{
    /**
     * @return array<string, FieldProfile>
     */
    public static function fields(): array
    {
        return [
            'employee.email' => FieldProfile::P1_INTERNAL,
            'employee.mobile' => FieldProfile::P1_INTERNAL,
            'employee.address' => FieldProfile::P1_INTERNAL,
            'employee.id_number' => FieldProfile::P2_SENSITIVE,
            'employee.bank_account' => FieldProfile::P2_SENSITIVE,
            'employee.contract_amount' => FieldProfile::P2_SENSITIVE,
            'employee.salary' => FieldProfile::P3_RESTRICTED,
            'employee.health' => FieldProfile::P3_RESTRICTED,
            'employee.background_check' => FieldProfile::P3_RESTRICTED,
            'payslip.gross' => FieldProfile::P3_RESTRICTED,
            'payslip.net' => FieldProfile::P3_RESTRICTED,
            'payslip.deductions' => FieldProfile::P3_RESTRICTED,
            'compensation.base_salary_cents' => FieldProfile::P3_RESTRICTED,
        ];
    }

    public static function declared(string $fieldKey): ?FieldProfile
    {
        return self::fields()[$fieldKey] ?? null;
    }
}
