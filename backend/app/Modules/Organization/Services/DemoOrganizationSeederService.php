<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class DemoOrganizationSeederService implements DemoOrganizationSeeder
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array{legal_entity_id: int, org_unit_ids: array<string, int>}
     */
    public function seedDemo(): array
    {
        $entity = LegalEntity::query()->firstOrCreate(
            ['tenant_id' => 1, 'code' => 'DEMO-CN'],
            [
                'name' => 'Demo China Co Ltd',
                'country_code' => 'CN',
                'timezone' => 'Asia/Shanghai',
                'currency_code' => 'CNY',
                'status' => 'ACTIVE',
            ],
        );

        $ids = [];
        $root = $this->orgUnit($entity, 'DEMO-HQ', null, 'Demo Headquarters', 'COMPANY');
        $ids['root'] = (int) $root->id;
        $engineering = $this->orgUnit($entity, 'DEMO-ENG', $root, 'Engineering', 'DIVISION');
        $ids['engineering'] = (int) $engineering->id;
        $platform = $this->orgUnit($entity, 'DEMO-PLATFORM', $engineering, 'Platform', 'TEAM');
        $ids['platform'] = (int) $platform->id;
        $sales = $this->orgUnit($entity, 'DEMO-SALES', $root, 'Sales', 'DIVISION');
        $ids['sales'] = (int) $sales->id;

        return ['legal_entity_id' => (int) $entity->id, 'org_unit_ids' => $ids];
    }

    private function orgUnit(LegalEntity $entity, string $code, ?OrgUnit $parent, string $name, string $type): OrgUnit
    {
        $unit = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => (int) $entity->tenant_id, 'code' => $code],
            [
                'legal_entity_id' => (int) $entity->id,
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
            'tenant_id' => (int) $entity->tenant_id,
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
                    'tenant_id' => (int) $entity->tenant_id,
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
