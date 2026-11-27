<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TravelChangeAttendanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_and_attendance_tables_exist_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('travel_request_changes'));
        $this->assertTrue(Schema::hasColumns('travel_request_changes', [
            'travel_id',
            'employment_id',
            'case_id',
            'original_json',
            'proposed_json',
            'reason',
            'status',
            'recorded_at',
            'applied_at',
        ]));
        $this->assertTrue(Schema::hasTable('travel_attendance_links'));
        $this->assertTrue(Schema::hasColumns('travel_attendance_links', [
            'travel_id',
            'employment_id',
            'from_date',
            'to_date',
            'source_case_id',
            'status',
            'retired_at',
            'retire_reason',
        ]));
    }
}
