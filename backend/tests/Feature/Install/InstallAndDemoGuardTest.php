<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Modules\IAM\Models\User;
use App\Modules\People\Models\Employment;
use App\Modules\Platform\Demo\DemoDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class InstallAndDemoGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_database_seeder_never_creates_demo_identity_or_business_rows(): void
    {
        config(['iam.demo_seed_password' => 'Synthetic!Demo2026']);

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, User::query()->count(), 'The default seeder must not create demo accounts.');
        $this->assertSame(0, Employment::query()->count(), 'The default seeder must not create employees.');
        $this->assertSame(0, DemoDataset::query()->count(), 'The default seeder must not register a demo dataset.');
    }

    public function test_legacy_demo_identity_seed_is_refused_in_production(): void
    {
        config(['iam.demo_seed_password' => 'Synthetic!Demo2026']);
        $this->app['env'] = 'production';

        $exit = Artisan::call('iam:seed-demo');

        $this->assertNotSame(0, $exit, 'Production must reject the legacy demo seeder.');
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Employment::query()->count());
    }
}
