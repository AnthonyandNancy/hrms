<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

/**
 * Closed case-type -> published template-code mapping.
 *
 * Business modules own their case-type vocabulary (`LEAVE_REQUEST`,
 * `ATTENDANCE_CORRECTION`) while the template catalog uses dotted flow codes
 * (`leave.request`). Resolving templates by string case-folding would let any
 * caller reach any published template, so the mapping is explicit and unknown
 * types stay unmapped (CaseCommandService then fails closed).
 */
final class CaseTemplateCatalog
{
    /**
     * @var array<string, string>
     */
    private const TEMPLATE_CODES = [
        'LEAVE_REQUEST' => 'leave.request',
        'ATTENDANCE_CORRECTION' => 'attendance.correction',
        'TRAVEL_REQUEST' => 'travel.request',
        'TRANSFER_REQUEST' => 'transfer.request',
        'CONTRACT_RENEWAL' => 'contract.renewal',
        'ONBOARDING_REQUEST' => 'onboarding.request',
        'OFFBOARDING_REQUEST' => 'offboarding.request',
        'PAYROLL_APPROVAL' => 'payroll.approval',
    ];

    public static function templateCodeFor(string $caseType): ?string
    {
        return self::TEMPLATE_CODES[$caseType] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function templateCodes(): array
    {
        return array_values(array_unique(self::TEMPLATE_CODES));
    }
}
