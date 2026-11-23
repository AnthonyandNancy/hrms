<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Domain;

use App\Shared\Time\BusinessDate;

/**
 * Seniority continuity is an explicit policy, never inferred. The default is
 * PRESERVE_ORIGINAL for internal moves and REHIRE_DATE for new hires/rehires.
 */
final class SeniorityRule
{
    public const PRESERVE_ORIGINAL = 'PRESERVE_ORIGINAL';

    public const REHIRE_DATE = 'REHIRE_DATE';

    public const NEW_HIRE_DATE = 'NEW_HIRE_DATE';

    public function resolve(string $rule, string $previousSeniorityStart, string $hireDate): string
    {
        return match ($rule) {
            self::PRESERVE_ORIGINAL => BusinessDate::from($previousSeniorityStart)->value(),
            self::REHIRE_DATE, self::NEW_HIRE_DATE => BusinessDate::from($hireDate)->value(),
            default => throw new \InvalidArgumentException("Unknown seniority rule {$rule}."),
        };
    }
}
