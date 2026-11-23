<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Modules\People\Models\Employment;
use App\Shared\Time\AsOfQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AsOfQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_where_valid_on_is_half_open_and_grouped(): void
    {
        $this->seedEmployment(1, '2026-01-01', '2026-02-01');
        $this->seedEmployment(2, '2026-02-01', '9999-12-31');

        $ids = AsOfQuery::whereValidOn(Employment::query(), '2026-01-31')->orderBy('id')->pluck('id')->all();
        $this->assertSame([1], $ids);

        $ids = AsOfQuery::whereValidOn(Employment::query(), '2026-02-01')->orderBy('id')->pluck('id')->all();
        $this->assertSame([2], $ids);
    }

    public function test_where_known_at_respects_recorded_and_superseded_timestamps(): void
    {
        $this->seedEmployment(1, '2026-01-01', '9999-12-31', '2026-01-01 00:00:00', '2026-02-01 00:00:00');
        $this->seedEmployment(2, '2026-01-01', '9999-12-31', '2026-02-01 00:00:00', null);

        $known = AsOfQuery::whereKnownAt(Employment::query(), \Carbon\CarbonImmutable::parse('2026-01-15T00:00:00Z'))->pluck('id')->all();
        $this->assertSame([1], $known);

        $known = AsOfQuery::whereKnownAt(Employment::query(), \Carbon\CarbonImmutable::parse('2026-02-01T00:00:00Z'))->pluck('id')->all();
        $this->assertSame([2], $known);
    }

    private function seedEmployment(int $id, string $from, string $to, string $recordedAt = '2026-01-01 00:00:00', ?string $supersededAt = null): void
    {
        \Illuminate\Support\Facades\DB::table('employments')->insert([
            'id' => $id,
            'tenant_id' => 1,
            'person_id' => 1,
            'legal_entity_id' => 1,
            'employee_no' => 'ASOF-'.$id,
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => $from,
            'valid_to' => $to,
            'hire_date' => $from,
            'termination_date' => null,
            'seniority_start_date' => $from,
            'recorded_at' => $recordedAt,
            'superseded_at' => $supersededAt,
            'source_type' => 'TEST',
        ]);
    }
}
