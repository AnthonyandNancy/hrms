<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Modules\Platform\Migration\Models\MigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrationRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_rehearsal_applies_all_domains_to_shadow_and_reconciles(): void
    {
        $output = storage_path('app/test-rehearsal/report.json');

        if (File::isDirectory(dirname($output))) {
            File::deleteDirectory(dirname($output));
        }

        $this->artisan('migrate:rehearsal', [
            '--round' => 1,
            '--dataset' => 'demo',
            '--output' => $output,
        ])->assertSuccessful();

        $this->assertSame(6, MigrationRun::query()->where('status', 'APPLIED')->count());
        $this->assertFileExists($output);
        $report = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['passed']);
        $this->assertSame('SHADOW', $report['target']);
        $this->assertCount(6, $report['domains']);
        $this->assertSame(6, MigrationRun::query()->where('mode', 'VALIDATE')->where('status', 'VALIDATED')->count());

        foreach ($report['domains'] as $domain) {
            $this->assertNotNull($domain['validation_run_id']);
            $this->assertSame('VALIDATE', MigrationRun::query()->findOrFail($domain['validation_run_id'])->mode->value);
        }
        $this->assertSame(0, \App\Modules\Platform\Migration\Models\MigrationShadowRow::query()
            ->where('domain', 'not like', 'SHADOW:%')->count());
    }

    public function test_rehearsal_requires_positive_round(): void
    {
        $this->artisan('migrate:rehearsal', [
            '--round' => 0,
            '--dataset' => 'demo',
        ])->assertExitCode(2);
    }

    public function test_rehearsal_stops_on_missing_dataset(): void
    {
        $this->artisan('migrate:rehearsal', [
            '--round' => 1,
            '--dataset' => 'no-such-fixture-set',
        ])->assertFailed();

        $this->assertSame(0, MigrationRun::query()->count());
    }
}
