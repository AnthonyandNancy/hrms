<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * Hard separation-of-duties conflicts. The rules list is code-owned; the
 * database sync only stores descriptors for UI and audit.
 */
final class SodRules
{
    /** @var list<array{0: string, 1: string}> */
    private const PAIRS = [
        ['payroll.run.prepare', 'payroll.run.approve'],
        ['payroll.run.prepare', 'payroll.run.publish'],
        ['payroll.run.review', 'payroll.run.approve'],
        ['iam.binding.manage', 'iam.audit.read'],
        ['iam.role.manage', 'iam.audit.read'],
        ['people.employee.manage', 'iam.audit.read'],
    ];

    /**
     * Return all conflicting pairs present in the given capability set.
     *
     * @param  list<string>  $capabilityCodes
     * @return list<array{0: string, 1: string}>
     */
    public static function conflicts(array $capabilityCodes): array
    {
        $set = array_values(array_unique($capabilityCodes));
        $conflicts = [];

        foreach (self::PAIRS as [$left, $right]) {
            if (in_array($left, $set, true) && in_array($right, $set, true)) {
                $pair = [$left, $right];
                sort($pair);
                $conflicts[] = $pair;
            }
        }

        $conflicts = array_values(array_unique($conflicts, SORT_REGULAR));
        sort($conflicts);

        return $conflicts;
    }

    /**
     * @param  list<string>  $capabilityCodes
     */
    public static function assertAllowed(array $capabilityCodes): void
    {
        $conflicts = self::conflicts($capabilityCodes);

        if ($conflicts !== []) {
            throw new SodConflictException($conflicts);
        }
    }
}
