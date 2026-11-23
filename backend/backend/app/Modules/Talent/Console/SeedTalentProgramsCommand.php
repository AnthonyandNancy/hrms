<?php

declare(strict_types=1);

namespace App\Modules\Talent\Console;

use App\Modules\Talent\Models\PerformanceTemplate;
use App\Modules\Talent\Models\TrainingProgram;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

final class SeedTalentProgramsCommand extends Command
{
    protected $signature = 'talent:seed-demo {--tenant=1 : Tenant id}';

    protected $description = 'Idempotently seed synthetic talent reference data.';

    public function handle(Clock $clock): int
    {
        $tenantId = max(1, (int) $this->option('tenant'));

        TrainingProgram::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'DEMO-SAFETY'],
            ['title' => 'Demo Workplace Safety', 'status' => 'ACTIVE', 'default_capacity' => 20, 'recorded_at' => $clock->now()],
        );

        PerformanceTemplate::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'DEMO-ANNUAL', 'version' => 1],
            ['name' => 'Demo annual review', 'sections_json' => ['goals', 'self_assessment', 'manager_assessment'], 'recorded_at' => $clock->now()],
        );

        $this->info('Talent demo reference data is seeded for tenant '.$tenantId.'.');

        return self::SUCCESS;
    }
}
