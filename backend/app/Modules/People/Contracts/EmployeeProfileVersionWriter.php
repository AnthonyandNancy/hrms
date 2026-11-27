<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Append-only writer for the four versioned employee-profile subresources.
 *
 * This is a narrow seam used by the approved-change applier. It deliberately
 * exposes no draft or approval operations, so the Workflow effect cannot
 * create a second approval chain or call the controller-facing service.
 */
interface EmployeeProfileVersionWriter
{
    /**
     * Append a new version of an existing profile row and retire the previous
     * version. The returned payload is the public projection of the new row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function revise(string $resource, int $employmentId, int $id, array $data, ?int $actorUserId = null): array;

    /**
     * Read the public projection of a change request after it was approved.
     *
     * @return array<string, mixed>
     */
    public function readChangeRequest(int $employmentId, int $id): array;
}
