<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Organization-owned demo provisioning used by the People demo command so
 * People never writes organization tables directly.
 */
interface DemoOrganizationSeeder
{
    /**
     * @return array{legal_entity_id: int, org_unit_ids: array<string, int>}
     */
    public function seedDemo(): array;
}
