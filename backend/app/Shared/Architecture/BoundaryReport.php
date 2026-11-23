<?php

declare(strict_types=1);

namespace App\Shared\Architecture;

/**
 * Deterministic result of one boundary scan. Violations fail the build;
 * warnings record table accesses that cannot be attributed yet.
 */
final readonly class BoundaryReport
{
    /**
     * @param  list<BoundaryFinding>  $violations
     * @param  list<BoundaryFinding>  $warnings
     */
    public function __construct(
        private int $filesScanned,
        private array $violations = [],
        private array $warnings = [],
    ) {}

    public function filesScanned(): int
    {
        return $this->filesScanned;
    }

    public function isClean(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return list<BoundaryFinding>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * @return list<BoundaryFinding>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
