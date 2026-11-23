<?php

declare(strict_types=1);

namespace Tests\Unit\Organization;

use App\Modules\Organization\Services\ClosureBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClosureBuilderTest extends TestCase
{
    public function test_it_builds_self_and_ancestor_paths_with_depth(): void
    {
        $rows = ClosureBuilder::build([1 => null, 2 => 1, 3 => 2, 4 => 1]);

        $this->assertContains(['ancestor_id' => 1, 'descendant_id' => 1, 'depth' => 0], $rows);
        $this->assertContains(['ancestor_id' => 1, 'descendant_id' => 3, 'depth' => 2], $rows);
        $this->assertContains(['ancestor_id' => 2, 'descendant_id' => 3, 'depth' => 1], $rows);
        $this->assertContains(['ancestor_id' => 1, 'descendant_id' => 4, 'depth' => 1], $rows);
    }

    public function test_it_detects_a_parent_cycle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClosureBuilder::build([1 => 2, 2 => 1]);
    }
}
