<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class DemoOrganizationSeederService implements DemoOrganizationSeeder
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array{org_unit_ids: array<string, int>}
     */
    public function seedDemo(): array
    {
        $ids = [];
        $root = $this->orgUnit('ORG-HQ', null, 'Demo Headquarters', 'COMPANY');
        $ids['root'] = (int) $root->id;
        $engineering = $this->orgUnit('ORG-ENG', $root, 'Demo Engineering', 'DEPARTMENT');
        $ids['engineering'] = (int) $engineering->id;
        $platform = $this->orgUnit('ORG-PLATFORM', $root, 'Demo Platform', 'DEPARTMENT');
        $ids['platform'] = (int) $platform->id;
        $sales = $this->orgUnit('ORG-SALES', $root, 'Demo Sales', 'DEPARTMENT');
        $ids['sales'] = (int) $sales->id;

        return ['org_unit_ids' => $ids];
    }

    private function orgUnit(string $code, ?OrgUnit $parent, string $name, string $type): OrgUnit
    {
        $unit = OrgUnit::query()->firstOrCreate(
            ['code' => $code],
            [
                'parent_id' => $parent?->id,
                'name' => $name,
                'type' => $type,
                'status' => 'ACTIVE',
                'valid_from' => '2026-01-01',
                'valid_to' => '9999-12-31',
                'recorded_at' => $this->clock->now(),
                'source_type' => 'DEMO_SEED',
            ],
        );

        $exists = OrgUnitClosure::query()
            ->where('ancestor_id', $unit->id)
            ->where('descendant_id', $unit->id)
            ->where('valid_from', '<=', '2026-11-23')
            ->where('valid_to', '>', '2026-11-23')
            ->exists();

        if ($exists) {
            return $unit;
        }

        DB::table('org_unit_closure')->insert([
            'ancestor_id' => (int) $unit->id,
            'descendant_id' => (int) $unit->id,
            'depth' => 0,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'DEMO_SEED',
        ]);

        if ($parent !== null) {
            $ancestors = OrgUnitClosure::query()
                ->where('descendant_id', $parent->id)
                ->where('valid_from', '<=', '2026-11-23')
                ->where('valid_to', '>', '2026-11-23')
                ->get();

            foreach ($ancestors as $ancestor) {
                DB::table('org_unit_closure')->insert([
                    'ancestor_id' => (int) $ancestor->ancestor_id,
                    'descendant_id' => (int) $unit->id,
                    'depth' => (int) $ancestor->depth + 1,
                    'valid_from' => '2026-01-01',
                    'valid_to' => '9999-12-31',
                    'recorded_at' => $this->clock->now(),
                    'source_type' => 'DEMO_SEED',
                ]);
            }
        }

        return $unit;
    }
}
