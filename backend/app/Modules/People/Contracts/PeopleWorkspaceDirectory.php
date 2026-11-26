<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Read port for the authenticated person's own workspace profile. It never
 * accepts a user-supplied employment id, so it cannot be used as an
 * enumeration surface.
 */
interface PeopleWorkspaceDirectory
{
    public function profile(int $employmentId, string $on): ?PeopleWorkspaceProfile;
}
