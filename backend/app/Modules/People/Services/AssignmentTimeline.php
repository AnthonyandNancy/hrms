<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Exceptions\PrimaryAssignmentOverlapException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\DateRange;

/**
 * Pure overlap rules used by both the write service (inside its transaction
 * lock) and focused unit tests.
 */
final class AssignmentTimeline
{
    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  int|null  $ignoreId  Existing row being replaced, if any.
     */
    public static function assertNoPrimaryOverlap(array $assignments, ?int $ignoreId = null): void
    {
        $primary = [];

        foreach ($assignments as $assignment) {
            $id = isset($assignment['id']) ? (int) $assignment['id'] : null;

            if ($id !== null && $id === $ignoreId) {
                continue;
            }

            $isPrimary = ($assignment['is_primary'] ?? false) === true
                || ($assignment['assignment_type'] ?? null) === 'PRIMARY';

            if (! $isPrimary) {
                continue;
            }

            $range = new DateRange((string) $assignment['valid_from'], (string) $assignment['valid_to']);

            foreach ($primary as $existing) {
                if ($range->overlaps($existing['range'])) {
                    throw new PrimaryAssignmentOverlapException([
                        'employment_id' => $assignment['employment_id'] ?? null,
                        'assignment_id' => $id,
                        'conflicts_with_assignment_id' => $existing['id'],
                        'valid_from' => $range->validFrom()->value(),
                        'valid_to' => $range->validTo()->value(),
                    ]);
                }
            }

            $primary[] = ['id' => $id, 'range' => $range];
        }
    }

    public static function covers(string $validFrom, string $validTo, BusinessDate $on): bool
    {
        return (new DateRange($validFrom, $validTo))->contains($on);
    }
}
