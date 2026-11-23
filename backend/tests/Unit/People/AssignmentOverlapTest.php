<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use App\Modules\People\Exceptions\PrimaryAssignmentOverlapException;
use App\Modules\People\Services\AssignmentTimeline;
use PHPUnit\Framework\TestCase;

final class AssignmentOverlapTest extends TestCase
{
    public function test_two_primary_intervals_may_not_overlap(): void
    {
        $this->expectException(PrimaryAssignmentOverlapException::class);

        AssignmentTimeline::assertNoPrimaryOverlap([
            ['id' => 1, 'assignment_type' => 'PRIMARY', 'is_primary' => true, 'valid_from' => '2026-01-01', 'valid_to' => '2026-04-01'],
            ['id' => 2, 'assignment_type' => 'PRIMARY', 'is_primary' => true, 'valid_from' => '2026-03-31', 'valid_to' => '2026-06-01'],
        ]);
    }

    public function test_touching_half_open_intervals_do_not_overlap(): void
    {
        AssignmentTimeline::assertNoPrimaryOverlap([
            ['id' => 1, 'assignment_type' => 'PRIMARY', 'is_primary' => true, 'valid_from' => '2026-01-01', 'valid_to' => '2026-04-01'],
            ['id' => 2, 'assignment_type' => 'PRIMARY', 'is_primary' => true, 'valid_from' => '2026-04-01', 'valid_to' => '2026-06-01'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_concurrent_secondments_and_matrix_assignments_are_allowed(): void
    {
        AssignmentTimeline::assertNoPrimaryOverlap([
            ['id' => 1, 'assignment_type' => 'PRIMARY', 'is_primary' => true, 'valid_from' => '2026-01-01', 'valid_to' => '9999-12-31'],
            ['id' => 2, 'assignment_type' => 'SECONDMENT', 'is_primary' => false, 'valid_from' => '2026-02-01', 'valid_to' => '2026-03-01'],
            ['id' => 3, 'assignment_type' => 'MATRIX', 'is_primary' => false, 'valid_from' => '2026-02-01', 'valid_to' => '2026-03-01'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_primary_flag_and_type_are_both_authoritative(): void
    {
        $this->expectException(PrimaryAssignmentOverlapException::class);

        AssignmentTimeline::assertNoPrimaryOverlap([
            ['id' => 1, 'assignment_type' => 'PRIMARY', 'is_primary' => true, 'valid_from' => '2026-01-01', 'valid_to' => '9999-12-31'],
            ['id' => 2, 'assignment_type' => 'SECONDMENT', 'is_primary' => true, 'valid_from' => '2026-01-01', 'valid_to' => '9999-12-31'],
        ]);
    }
}
