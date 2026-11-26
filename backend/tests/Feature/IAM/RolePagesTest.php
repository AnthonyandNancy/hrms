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
        $this->assertFalse(Schema::hasColumn('role_pages', 'tenant_id'));
        $this->assertFalse(Schema::hasColumn('role_pages', 'legal_entity_id'));

        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->artisan('iam:sync-capabilities')->assertSuccessful();

        $this->assertSame(160, RolePage::query()->where('role_code', 'SYSTEM_ADMIN')->count());
        $this->assertSame(160, RolePage::query()->where('role_code', 'SUPER_ADMIN')->count());
        $this->assertGreaterThan(0, RolePage::query()->where('role_code', 'HR_ADMIN')->count());
        $this->assertLessThan(160, RolePage::query()->where('role_code', 'HR_ADMIN')->count());
        $this->assertGreaterThan(0, RolePage::query()->where('role_code', 'EMPLOYEE')->count());
        $this->assertSame(
            RolePage::query()->count(),
            RolePage::query()
                ->selectRaw('role_code, page_code')
                ->distinct()
                ->get()
                ->count(),
        );
    }
}
