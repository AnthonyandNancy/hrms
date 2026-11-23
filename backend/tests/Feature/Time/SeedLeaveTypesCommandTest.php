<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tenant with no leave types cannot open a leave account or import opening
 * balances, which previously made every fresh tenant unable to use leave at
 * all. The catalog command is the supported creation path.
 */
final class SeedLeaveTypesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_reference_catalog_idempotently(): void
    {
        $this->artisan('time:seed-leave-types', [
            '--tenant' => 1,
            '--annual-days' => '15.00',
            '--legal-basis' => 'Synthetic legal basis',
        ])->assertSuccessful();

        $this->assertSame(3, LeaveType::query()->where('tenant_id', 1)->count());

        foreach (['ANNUAL', 'SICK', 'PERSONAL'] as $code) {
            $this->assertDatabaseHas('leave_types', ['tenant_id' => 1, 'code' => $code]);
        }

        $annual = LeaveType::query()->where('tenant_id', 1)->where('code', 'ANNUAL')->firstOrFail();
        $this->assertTrue((bool) $annual->is_statutory);
        $this->assertTrue((bool) $annual->is_annual);
        $this->assertSame(15.0, (float) $annual->annual_entitlement_days);

        // Re-running must not duplicate or overwrite the configured entitlement.
        $this->artisan('time:seed-leave-types', ['--tenant' => 1, '--annual-days' => '20.00'])->assertSuccessful();

        $this->assertSame(3, LeaveType::query()->where('tenant_id', 1)->count());
        $this->assertSame(15.0, (float) $annual->fresh()->annual_entitlement_days);
    }

    public function test_it_does_not_invent_a_statutory_entitlement(): void
    {
        $this->artisan('time:seed-leave-types', ['--tenant' => 1])->assertSuccessful();

        $annual = LeaveType::query()->where('tenant_id', 1)->where('code', 'ANNUAL')->firstOrFail();

        $this->assertNull($annual->annual_entitlement_days);
    }

    public function test_it_rejects_a_malformed_entitlement(): void
    {
        $this->artisan('time:seed-leave-types', [
            '--tenant' => 1,
            '--annual-days' => 'abc',
        ])->assertExitCode(2);

        $this->assertSame(0, LeaveType::query()->count());
    }

    public function test_it_keeps_tenants_isolated(): void
    {
        $this->artisan('time:seed-leave-types', ['--tenant' => 1, '--annual-days' => '15.00'])->assertSuccessful();
        $this->artisan('time:seed-leave-types', ['--tenant' => 2, '--annual-days' => '10.00'])->assertSuccessful();

        $this->assertSame(3, LeaveType::query()->where('tenant_id', 1)->count());
        $this->assertSame(3, LeaveType::query()->where('tenant_id', 2)->count());
    }
}
