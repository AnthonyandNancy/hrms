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
    protected $signature = 'org:rebuild-closure';

    protected $description = 'Append a rebuilt closure version for the company without rewriting history';

    public function handle(Clock $clock): int
    {
        $units = OrgUnit::query()
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->get();

        if ($units->isEmpty()) {
            $this->error('No organization units found.');

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
