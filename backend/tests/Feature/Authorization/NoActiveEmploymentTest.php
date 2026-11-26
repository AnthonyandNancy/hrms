<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Tests\Support\AuthenticatesRequests;

/**
 * I3: a User is an account; an Employment is a working relationship. SELF
 * capabilities require a live employment and must fail with an explicit,
 * actionable 409 instead of silently resolving employmentId=0 (404) or an
 * empty success payload.
 */
final class NoActiveEmploymentTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_admin_without_employment_gets_explicit_business_error_on_self_pages(): void
    {
        $admin = $this->adminWithoutEmployment();
        $this->loginAs($admin);

        $this->getJson('/v1/time/leave/balances')
            ->assertConflict()
            ->assertJsonPath('code', 'NO_ACTIVE_EMPLOYMENT')
            ->assertJsonPath('data.reason', 'NO_ACTIVE_EMPLOYMENT');

        $this->getJson('/v1/time/attendance?month=2026-11')
            ->assertConflict()
            ->assertJsonPath('code', 'NO_ACTIVE_EMPLOYMENT');

        $this->getJson('/v1/payroll/payslips')
            ->assertConflict()
            ->assertJsonPath('code', 'NO_ACTIVE_EMPLOYMENT');
    }

    public function test_workspace_without_employment_returns_an_empty_projection_instead_of_error(): void
    {
        $admin = $this->adminWithoutEmployment();
        $this->loginAs($admin);

        $this->getJson('/v1/me/workspace')
            ->assertOk()
            ->assertJsonPath('data.employment_linked', false)
            ->assertJsonPath('data.profile.employmentId', null)
            ->assertJsonPath('data.leaveBalances', [])
            ->assertJsonPath('data.payslips', []);
    }

    private function adminWithoutEmployment(): User
    {
        $this->people->employmentByUser = [];
        $user = User::factory()->create([
            'auth_version' => 1,
            'is_privileged' => true,
            'mfa_enrolled_at' => now(),
        ]);

        foreach (['iam.session.manage', 'time.leave.read', 'time.attendance.read', 'payroll.payslip.read.self'] as $capability) {
            AuthorityBinding::factory()
                ->forPrincipal((int) $user->id, (int) $user->auth_version)
                ->anchor(AnchorType::SELF, null, FieldProfile::P1_INTERNAL)
                ->create([
                    'capability_code' => $capability,
                    'valid_from' => '2025-01-01 00:00:00',
                    'valid_to' => '2030-01-01 00:00:00',
                ]);
        }

        return $user;
    }
}
