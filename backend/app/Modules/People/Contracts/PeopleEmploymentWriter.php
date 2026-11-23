<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * People-owned lifecycle write port. All calls must run inside the caller's
 * transaction; People never starts an independent transaction here.
 */
interface PeopleEmploymentWriter
{
    /**
     * @param  array<string, mixed>  $person  Keys: legal_name, email?, mobile?, id_type?, id_number?
     */
    public function createPerson(int $tenantId, array $person, ?int $actorUserId): int;

    /**
     * @param  array<string, mixed>  $input
     */
    public function createEmployment(int $tenantId, array $input, ?int $actorUserId): int;

    /**
     * @param  array<string, mixed>  $input
     */
    public function createAssignment(int $tenantId, int $employmentId, array $input, ?int $actorUserId): int;

    /**
     * @param  array<string, mixed>  $input
     */
    public function appendTerms(int $tenantId, int $employmentId, array $input, ?int $actorUserId): int;

    public function endAssignment(int $tenantId, int $assignmentId, string $endExclusive, ?int $actorUserId, string $sourceType, ?int $sourceId = null): void;

    public function endEmployment(int $tenantId, int $employmentId, string $endExclusive, string $lastWorkingDate, ?int $actorUserId, string $sourceType, ?int $sourceId = null): void;

    public function setEmploymentStatus(int $tenantId, int $employmentId, string $status, ?int $actorUserId): void;

    /**
     * @param  array<string, mixed>  $input
     */
    public function appendDocument(int $tenantId, int $employmentId, array $input, ?int $actorUserId): int;

    /**
     * Cross-entity transfer as one transaction-visible unit: end the old
     * employment and create the new employment for the same person.
     *
     * @param  array<string, mixed>  $input
     */
    public function transferLegalEntity(int $tenantId, int $employmentId, array $input, ?int $actorUserId): int;

    public function linkUser(int $tenantId, int $userId, int $employmentId, string $validFrom, ?string $validTo, ?int $actorUserId): void;
}
