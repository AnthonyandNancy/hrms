<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Tests\Feature\Authorization\AuthorizationTestSupport;
use Tests\Support\AuthenticatesRequests;

final class PermissionPreviewApiTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-11-26T09:00:00Z'));
    }

    public function test_preview_reports_visible_pages_and_explicit_denial_reasons(): void
    {
        [, $headers] = $this->viewer(true);
        $target = User::factory()->create(['auth_version' => 1]);
        UserRole::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $target->id,
            'role_code' => 'HR_SPECIALIST',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'granted_by' => 10,
            'reason' => 'Synthetic preview test',
            'auth_version' => 1,
            'created_at' => now(),
        ]);
        foreach (['hr-employees', 'employee-detail'] as $pageCode) {
            RolePage::query()->updateOrCreate(
                ['role_code' => 'HR_SPECIALIST', 'page_code' => $pageCode],
                ['created_at' => now()],
            );
        }
        $this->grant($target, 'people.employee.read', FieldProfile::P2_SENSITIVE);

        $data = $this->getJson('/v1/users/'.$target->id.'/permissions-preview', $headers)->assertOk()->json('data');

        $paths = array_column($data['pages'], 'path');
        $this->assertContains('/hr/employees', $paths);
        $this->assertContains('/hr/employees/:id', $paths);
        $this->assertNotContains('/payroll/runs', $paths);

        $denied = collect($data['denied'])->firstWhere('page_code', 'payroll');
        $this->assertSame('PAGE_NOT_GRANTED', $denied['reason'] ?? null);
        $this->assertContains('people.employee.read', array_column($data['actions'], 'capability'));
        $this->assertSame(['HR_SPECIALIST'], $data['roles']);
    }

    public function test_granted_page_without_the_required_capability_is_capability_denied(): void
    {
        [, $headers] = $this->viewer(true);
        $target = User::factory()->create(['auth_version' => 1]);
        UserRole::query()->create([
            'tenant_id' => 1,
            'user_id' => (int) $target->id,
            'role_code' => 'HR_SPECIALIST',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'granted_by' => 10,
            'reason' => 'Synthetic preview test',
            'auth_version' => 1,
            'created_at' => now(),
        ]);
        RolePage::query()->updateOrCreate(['role_code' => 'HR_SPECIALIST', 'page_code' => 'hr-employees'], ['created_at' => now()]);

        $data = $this->getJson('/v1/users/'.$target->id.'/permissions-preview', $headers)->assertOk()->json('data');
        $denied = collect($data['denied'])->firstWhere('page_code', 'hr-employees');
        $this->assertSame('CAPABILITY_NOT_GRANTED', $denied['reason'] ?? null);
        $this->assertNotContains('/hr/employees', array_column($data['pages'], 'path'));
    }

    public function test_preview_is_capability_guarded(): void
    {
        $target = User::factory()->create(['auth_version' => 1]);
        [, $headers] = $this->viewer(false);

        $this->getJson('/v1/users/'.$target->id.'/permissions-preview', $headers)->assertForbidden();
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function viewer(bool $grantRead): array
    {
        $actor = User::factory()->create(['auth_version' => 1]);
        if ($grantRead) {
            $this->grant($actor, 'iam.user.read', FieldProfile::P1_INTERNAL);
        }
        $this->people->employmentByUser[(int) $actor->id] = [1];
        $this->organization->knownLegalEntities = [1];
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        return [$actor, ['X-CSRF-Token' => $auth['csrf']]];
    }

    private function grant(User $target, string $capability, FieldProfile $profile): void
    {
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $target->id, (int) $target->auth_version)
            ->anchor(AnchorType::COMPANY, null, $profile)
            ->create([
                'capability_code' => $capability,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
    }
}
