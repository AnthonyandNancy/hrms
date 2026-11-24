<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * Separation-of-duties risks, reported but never enforced at the capability
 * layer.
 *
 * Two kinds exist and they belong to different layers:
 *
 * - CUMULATIVE: one person holds two opposed capabilities (manage bindings and
 *   read audit evidence). This is a *job design* question. A wildcard holder
 *   necessarily carries it. It is surfaced for acknowledgement and quarterly
 *   review, and recorded in the audit trail.
 * - OBJECT_LEVEL: one person performs two steps on the *same object* (prepare
 *   and approve the same payroll run). This is a control invariant. It is
 *   enforced where the object exists — ApprovalService, BreakGlassService,
 *   ReopenService — and never by this class.
 *
 * Enforcing either kind here cannot work: the object-level rule needs an
 * object, and the cumulative rule needs a judgement. Hard-blocking the
 * capability set would make a legitimate super administrator impossible to
 * create, while protecting nothing (the check could not see two capabilities
 * at once).
 */
final class SodRules
{
    public const KIND_CUMULATIVE = 'CUMULATIVE';

    public const KIND_OBJECT_LEVEL = 'OBJECT_LEVEL';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private const RULES = [
        ['payroll.run.prepare', 'payroll.run.approve', self::KIND_OBJECT_LEVEL],
        ['payroll.run.prepare', 'payroll.run.publish', self::KIND_OBJECT_LEVEL],
        ['payroll.run.review', 'payroll.run.approve', self::KIND_OBJECT_LEVEL],
        ['iam.binding.manage', 'iam.audit.read', self::KIND_CUMULATIVE],
        ['iam.role.manage', 'iam.audit.read', self::KIND_CUMULATIVE],
        ['people.employee.manage', 'iam.audit.read', self::KIND_CUMULATIVE],
    ];

    /**
     * @param  list<string>  $capabilityCodes
     * @return list<array{pair: array{0: string, 1: string}, kind: string}>
     */
    public static function risks(array $capabilityCodes): array
    {
        $set = array_values(array_unique($capabilityCodes));
        $wildcard = in_array(CapabilityWildcard::WILDCARD, $set, true);
        $risks = [];

        foreach (self::RULES as [$left, $right, $kind]) {
            if (! $wildcard && ! (in_array($left, $set, true) && in_array($right, $set, true))) {
                continue;
            }

            $pair = [$left, $right];
            sort($pair);

            $risks[] = ['pair' => $pair, 'kind' => $kind];
        }

        return $risks;
    }

    /**
     * @param  list<string>  $capabilityCodes
     */
    public static function hasRisk(array $capabilityCodes): bool
    {
        return self::risks($capabilityCodes) !== [];
    }
}
