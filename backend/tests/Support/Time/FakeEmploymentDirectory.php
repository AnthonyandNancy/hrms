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
     * @var array<string, EmploymentSnapshot>
     */
    private array $snapshots = [];

    /**
     * @var array<string, int>
     */
    private array $userEmployments = [];

    public function add(EmploymentSnapshot $snapshot): void
    {
        $this->snapshots[$this->key($snapshot->tenantId, $snapshot->employmentId)] = $snapshot;
    }

    public function bindUser(int $tenantId, int $userId, int $employmentId): void
    {
        $this->userEmployments[$this->key($tenantId, $userId)] = $employmentId;
    }

    public function snapshot(int $tenantId, int $employmentId, string $businessDate): ?EmploymentSnapshot
    {
        $snapshot = $this->snapshots[$this->key($tenantId, $employmentId)] ?? null;

        if ($snapshot === null || $snapshot->validFrom > $businessDate || $snapshot->validTo <= $businessDate) {
            return null;
        }

        return $snapshot;
    }

    public function activeEmploymentIds(int $tenantId, string $businessDate): array
    {
        $ids = [];

        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->tenantId === $tenantId
                && $snapshot->validFrom <= $businessDate
                && $snapshot->validTo > $businessDate) {
                $ids[] = $snapshot->employmentId;
            }
        }

        sort($ids);

        return $ids;
    }

    public function employmentForUser(int $tenantId, int $userId, string $businessDate): ?int
    {
        $employmentId = $this->userEmployments[$this->key($tenantId, $userId)] ?? null;

        if ($employmentId === null) {
            return null;
        }

        return $this->snapshot($tenantId, $employmentId, $businessDate)?->employmentId;
    }

    public function legalEntityIdForEmployment(int $tenantId, int $employmentId): ?int
    {
        return $this->snapshots[$this->key($tenantId, $employmentId)]->legalEntityId ?? null;
    }

    private function key(int $tenantId, int $id): string
    {
        return $tenantId.':'.$id;
    }
}
