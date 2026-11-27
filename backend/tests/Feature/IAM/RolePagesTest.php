<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\RolePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RolePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_pages_table_is_single_company_and_idempotent(): void
    {
        $this->assertTrue(Schema::hasTable('role_pages'));
        $this->assertTrue(Schema::hasColumn('role_pages', 'role_code'));

        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $this->assertSame(37, RolePage::query()->where('role_code', 'SYSTEM_ADMIN')->count());
        $this->assertSame(37, RolePage::query()->where('role_code', 'SUPER_ADMIN')->count());
        $this->assertGreaterThan(0, RolePage::query()->where('role_code', 'HR_ADMIN')->count());
        $this->assertLessThan(37, RolePage::query()->where('role_code', 'HR_ADMIN')->count());
        $this->assertGreaterThan(0, RolePage::query()->where('role_code', 'EMPLOYEE')->count());
        $this->assertSame(
            (int) RolePage::query()->count(),
            (int) RolePage::query()
                ->selectRaw('role_code, page_code')
                ->distinct()
                ->count(),
        );
    }

    public function test_every_role_can_reach_the_base_security_pages(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        foreach (['SYSTEM_ADMIN', 'HR_ADMIN', 'HR_SPECIALIST', 'EMPLOYEE', 'LINE_MANAGER', 'PAYROLL_SPECIALIST'] as $role) {
            foreach (['workspace', 'account'] as $page) {
                $this->assertDatabaseHas('role_pages', ['role_code' => $role, 'page_code' => $page]);
            }
        }
    }

    public function test_every_role_template_can_use_its_own_workspace_and_security_pages(): void
    {
        $catalog = new \App\Modules\IAM\Authorization\RoleTemplateCatalog;

        foreach ($catalog->templates() as $template) {
            $capabilities = array_map(
                static fn ($grant): string => $grant->capabilityCode,
                $template->grants,
            );

            $coversSession = in_array('iam.session.manage', $capabilities, true)
                || in_array(\App\Shared\Authorization\CapabilityWildcard::WILDCARD, $capabilities, true);

            $this->assertTrue(
                $coversSession,
                $template->code.' must grant iam.session.manage (or the wildcard) so /me, /workspace, MFA and sessions resolve',
            );
        }
    }
}
