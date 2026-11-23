<?php

declare(strict_types=1);

namespace App\Modules\Organization\Commands;

use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Modules\Organization\Services\ClosureBuilder;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RebuildOrgClosureCommand extends Command
{
    protected $signature = 'org:rebuild-closure {--legal-entity= : Legal entity id to rebuild}';

    protected $description = 'Append a rebuilt closure version for a legal entity without rewriting history';

    public function handle(Clock $clock): int
    {
        $legalEntity = $this->option('legal-entity');

        if (! is_string($legalEntity) || $legalEntity === '') {
            $this->error('--legal-entity is required.');

            return self::FAILURE;
        }

        $units = OrgUnit::query()
            ->where('legal_entity_id', (int) $legalEntity)
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->get();

        if ($units->isEmpty()) {
            $this->error('No organization units found for the legal entity.');

            return self::FAILURE;
        }

        $parents = [];

        foreach ($units as $unit) {
            $parents[(int) $unit->id] = $unit->parent_id !== null ? (int) $unit->parent_id : null;
        }

        $rows = ClosureBuilder::build($parents);
        $today = $clock->now()->format('Y-m-d');

        DB::transaction(function () use ($rows, $today, $clock): void {
            foreach ($rows as $row) {
                $existing = OrgUnitClosure::query()
                    ->where('ancestor_id', $row['ancestor_id'])
                    ->where('descendant_id', $row['descendant_id'])
                    ->where('valid_to', '>', $today)
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                OrgUnitClosure::query()->create([
                    'tenant_id' => (int) OrgUnit::query()->whereKey($row['ancestor_id'])->value('tenant_id'),
                    'ancestor_id' => $row['ancestor_id'],
                    'descendant_id' => $row['descendant_id'],
                    'depth' => $row['depth'],
                    'valid_from' => $today,
                    'valid_to' => '9999-12-31',
                    'recorded_at' => $clock->now(),
                    'source_type' => 'REBUILD',
                ]);
            }
        });

        $this->info('Closure rebuild appended '.count($rows).' path(s).');

        return self::SUCCESS;
    }
}
