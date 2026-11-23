<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Demo\Services\DemoSeeder;
use App\Modules\Platform\Demo\Services\DemoVerifier;
use App\Modules\Platform\Migration\Models\MigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MigrationTargetRegistryFakes;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_idempotent_and_verify_passes_in_honest_shadow_mode(): void
    {
        MigrationTargetRegistryFakes::install();

        $seeder = app(DemoSeeder::class);
        $first = $seeder->seed();
        $second = $seeder->seed();

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame('SHADOW', $first['mode']);
        $this->assertCount(6, $first['domains']);
        $this->assertSame(12, MigrationRun::query()->where('domain', 'people')->where('status', 'APPLIED')->count() / 1 === 1.0 ? 12 : MigrationRun::query()->count());

        $reports = app(DemoVerifier::class)->verify();
        $this->assertTrue($reports['passed'], json_encode($reports['checks']));
        $this->assertSame('SHADOW', $reports['mode']);
        $this->assertNotEmpty($reports['deferred']);
        $this->assertNotEmpty($reports['walkthrough']);
    }

    public function test_reset_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->assertTrue(app()->environment('production'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('demo:reset is refused in production environments.');

        app(DemoSeeder::class)->reset();
    }

    public function test_reset_removes_shadow_staging_and_dataset_rows(): void
    {
        MigrationTargetRegistryFakes::install();
        app(DemoSeeder::class)->seed();

        $deleted = app(DemoSeeder::class)->reset();

        $this->assertGreaterThan(0, $deleted);
        $this->assertSame(0, MigrationRun::query()->count());
        $this->assertSame(0, \App\Modules\Platform\Demo\DemoDataset::query()->count());
    }
}
