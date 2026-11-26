<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\EmploymentSnapshot;

/**
 * Deterministic test double for the People port. Time must never read People
 * tables; tests seed employment facts through this adapter.
 */
final class FakeEmploymentDirectory implements EmploymentDirectory
{
    /**
     * @var array<int, EmploymentSnapshot>
     */
    private array $snapshots = [];

    /**
     * @var array<int, int>
     */
    private array $userEmployments = [];

    public function add(EmploymentSnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->employmentId] = $snapshot;
    }

    public function bindUser(int $userId, int $employmentId): void
    {
        $this->userEmployments[$userId] = $employmentId;
    }

    public function snapshot(int $employmentId, string $businessDate): ?EmploymentSnapshot
    {
        $snapshot = $this->snapshots[$employmentId] ?? null;

        if ($snapshot === null || $snapshot->validFrom > $businessDate || $snapshot->validTo <= $businessDate) {
            return null;
        }

        return $snapshot;
    }

    public function activeEmploymentIds(string $businessDate): array
    {
        $ids = [];

        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->validFrom <= $businessDate
                && $snapshot->validTo > $businessDate) {
                $ids[] = $snapshot->employmentId;
            }
        }

        sort($ids);

        return $ids;
    }

    public function employmentForUser(int $userId, string $businessDate): ?int
    {
        $employmentId = $this->userEmployments[$userId] ?? null;

        if ($employmentId === null) {
            return null;
        }

        return $this->snapshot($employmentId, $businessDate)?->employmentId;
    }
}
