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
    public function createPerson(array $person, ?int $actorUserId): int;

    /**
     * @param  array<string, mixed>  $input
     */
    public function createEmployment(array $input, ?int $actorUserId): int;

    /**
     * @param  array<string, mixed>  $input
     */
    public function createAssignment(int $employmentId, array $input, ?int $actorUserId): int;

    /**
     * @param  array<string, mixed>  $input
     */
    public function appendTerms(int $employmentId, array $input, ?int $actorUserId): int;

    public function endAssignment(int $assignmentId, string $endExclusive, ?int $actorUserId, string $sourceType, ?int $sourceId = null): void;

    public function endEmployment(int $employmentId, string $endExclusive, string $lastWorkingDate, ?int $actorUserId, string $sourceType, ?int $sourceId = null): void;

    public function setEmploymentStatus(int $employmentId, string $status, ?int $actorUserId): void;

    /**
     * @param  array<string, mixed>  $input
     */
    public function appendDocument(int $employmentId, array $input, ?int $actorUserId): int;

    public function linkUser(int $userId, int $employmentId, string $validFrom, ?string $validTo, ?int $actorUserId): void;
}
