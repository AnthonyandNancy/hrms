<?php

declare(strict_types=1);

namespace App\Modules\Platform\Demo\Services;

use App\Modules\Platform\Demo\DemoDataset;
use App\Modules\Platform\Features\FeatureFlagService;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunMode;
use App\Modules\Platform\Migration\Models\MigrationIssue;
use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Models\MigrationShadowRow;
use App\Modules\Platform\Migration\Models\StagingRow;
use App\Modules\Platform\Migration\Services\MigrationRunner;
use App\Modules\Platform\Migration\Services\MigrationTargetRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The demo command is honest about which target it wrote to. Every business
 * module is now merged, so `--live` imports the coherent 12-person dataset into
 * the real People/Payroll/Time tables through the same importer ports. Without
 * `--live` (or when a target is unavailable) the run stays in SHADOW mode: the
 * rows are rehearsed in the shadow target only and the report says so.
 */
final class DemoSeeder
{
    public const TENANT_ID = 1;

    public const DATASET = 'demo';

    public const BUSINESS_DOMAINS = [
        'organizations',
        'people',
        'assignments',
        'terms',
        'leave_openings',
        'payroll_ytd',
    ];

    public function __construct(
        private readonly MigrationRunner $runner,
        private readonly MigrationTargetRegistry $targets,
        private readonly FeatureFlagService $flags,
    ) {}

    /**
     * @return array{dataset: string, mode: string, created: bool, domains: array<string, array<string, mixed>>, deferred: array<string, string>}
     */
    public function seed(bool $allowLive = false, int $tenantId = self::TENANT_ID): array
    {
        $fixtureRoot = $this->fixtureRoot();
        $mapping = $fixtureRoot.'/mapping/demo.csv';
        $domains = [];
        $deferred = [];
        $targetStatus = $this->targets->status();
        $liveAvailable = true;

        foreach (self::BUSINESS_DOMAINS as $domainValue) {
            if (! ($targetStatus[$domainValue]['available'] ?? false)) {
                $liveAvailable = false;
            }
        }

        $useLive = $allowLive && $liveAvailable;

        foreach (self::BUSINESS_DOMAINS as $domainValue) {
            $domain = MigrationDomain::from($domainValue);
            $file = $fixtureRoot.'/known-good/'.$domainValue.'.csv';
            $run = $this->runner->apply(
                $domain,
                $tenantId,
                $file,
                [$mapping],
                MigrationRunMode::APPLY,
                ! $useLive,
            );
            $domains[$domainValue] = [
                'run_id' => (int) $run->id,
                'status' => $run->status->value,
                'mode' => $useLive ? 'LIVE' : 'SHADOW',
                'rows_read' => $run->rows_read,
                'rows_created' => $run->rows_created,
                'rows_updated' => $run->rows_updated,
                'rows_skipped' => $run->rows_skipped,
            ];

            if (! $useLive) {
                $deferred[$domainValue] = 'SHADOW run requested or a live target was unavailable; data is rehearsal shadow only and not in business tables.';
            }

            if (! $run->status->isSuccessful()) {
                throw new RuntimeException("Demo domain '{$domainValue}' failed with status {$run->status->value}.");
            }
        }

        foreach ($this->flags->flags() as $flag) {
            $this->ensureDefaultFlag($flag, $tenantId);
        }

        $hashParts = [];

        foreach (self::BUSINESS_DOMAINS as $domainValue) {
            $hashParts[] = hash_file('sha256', $fixtureRoot.'/known-good/'.$domainValue.'.csv') ?: '';
        }

        $hash = hash('sha256', implode('|', $hashParts));
        $now = CarbonImmutable::now('UTC');

        $existingDataset = DemoDataset::query()
            ->where('tenant_id', $tenantId)
            ->where('dataset', self::DATASET)
            ->first();
        $created = $existingDataset === null;

        $dataset = DemoDataset::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'dataset' => self::DATASET],
            [
                'status' => 'READY',
                'mode' => $useLive ? 'LIVE' : 'SHADOW',
                'populated_json' => $domains,
                'deferred_json' => $deferred,
                'fixture_hash' => $hash,
                'seeded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return [
            'dataset' => self::DATASET,
            'mode' => $dataset->mode,
            'created' => $created,
            'domains' => $domains,
            'deferred' => $deferred,
        ];
    }

    public function reset(int $tenantId = self::TENANT_ID): int
    {
        if (app()->environment('production')) {
            throw new RuntimeException('demo:reset is refused in production environments.');
        }

        $deleted = 0;
        $deleted += MigrationShadowRow::query()->where('tenant_id', $tenantId)->delete();

        $runIds = MigrationRun::query()->where('tenant_id', $tenantId)->pluck('id')->all();

        if ($runIds !== []) {
            MigrationIssue::query()->whereIn('run_id', $runIds)->delete();

            foreach (MigrationDomain::cases() as $domain) {
                StagingRow::forDomain($domain)
                    ->newQuery()
                    ->whereIn('run_id', $runIds)
                    ->delete();
            }

            $deleted += MigrationRun::query()->whereIn('id', $runIds)->delete();
        }

        $deleted += DemoDataset::query()->where('tenant_id', $tenantId)->delete();
        $deleted += DB::table('feature_flags')->where('tenant_id', $tenantId)->delete();

        return $deleted;
    }

    private function ensureDefaultFlag(string $flag, int $tenantId): void
    {
        $exists = DB::table('feature_flags')
            ->where('tenant_id', $tenantId)
            ->where('flag', $flag)
            ->exists();

        if (! $exists) {
            $this->flags->set($flag, $tenantId, false, null, 'demo seed default-off risky domain');
        }
    }

    private function fixtureRoot(): string
    {
        $path = base_path('database/migration-fixtures');

        if (! is_dir($path)) {
            throw new RuntimeException('database/migration-fixtures is missing.');
        }

        return $path;
    }
}
