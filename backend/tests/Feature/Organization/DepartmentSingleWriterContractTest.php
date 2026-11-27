<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Tests\Support\PeopleTestCase;

/**
 * Route-level proof that M05 does not expose a second org-unit writer.
 * The POST /v1/org-units token remains only because routes/api.php is outside
 * this module's allowed file domain; it must be inert until integration deletes it.
 */
final class DepartmentSingleWriterContractTest extends PeopleTestCase
{
    public function test_org_unit_create_route_cannot_open_even_with_a_valid_payload(): void
    {
        $this->postJson('/v1/org-units', [
            'code' => 'SECOND-WRITER',
            'name' => 'Second Writer',
            'type' => 'DEPARTMENT',
            'valid_from' => '2026-01-01',
        ], [
            'X-CSRF-Token' => 'test-csrf',
            'Idempotency-Key' => 'second-writer',
        ])->assertNotFound();

        $this->assertDatabaseMissing('org_units', ['code' => 'SECOND-WRITER']);
    }
}
