<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;

trait EngagementFixture
{
    /**
     * Two employments in two different organization units, each linked to a
     * synthetic active user. Org A belongs to the manager's audience; Org B
     * must never see Org A notices.
     *
     * @return array{org_a_id: int, org_b_id: int, manager_id: int, user_a_id: int, user_b_id: int, employment_a_id: int, employment_b_id: int}
     */
    protected function workforceFixture(int $tenantId = 1): array
    {
        $scenario = $this->scenario($tenantId);
        $entity = $scenario->legalEntity();
        $orgA = $scenario->orgUnit($entity, 'ORG-A', null, 'Org A');
        $orgB = $scenario->orgUnit($entity, 'ORG-B', null, 'Org B');

        $manager = User::factory()->create(['tenant_id' => $tenantId]);
        $userA = User::factory()->create(['tenant_id' => $tenantId]);
        $userB = User::factory()->create(['tenant_id' => $tenantId]);

        $employmentA = $scenario->employment($scenario->person(), $entity, 'E-A');
        $employmentB = $scenario->employment($scenario->person(), $entity, 'E-B');

        $scenario->assignment($employmentA, $orgA);
        $scenario->assignment($employmentB, $orgB);
        $scenario->userLink((int) $userA->id, $employmentA);
        $scenario->userLink((int) $userB->id, $employmentB);

        foreach ([$manager, $userA, $userB] as $user) {
            UserRole::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => (int) $user->id,
                'role_code' => 'EMPLOYEE',
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => '9999-12-31 00:00:00',
                'granted_by' => null,
                'reason' => 'TEST',
                'auth_version' => (int) $user->auth_version,
                'created_at' => $this->clock->now(),
            ]);
        }

        return [
            'org_a_id' => (int) $orgA->id,
            'org_b_id' => (int) $orgB->id,
            'manager_id' => (int) $manager->id,
            'user_a_id' => (int) $userA->id,
            'user_b_id' => (int) $userB->id,
            'employment_a_id' => (int) $employmentA->id,
            'employment_b_id' => (int) $employmentB->id,
        ];
    }
}
