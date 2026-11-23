<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use InvalidArgumentException;

/**
 * Builds ancestor-descendant closure rows for an acyclic parent map. The
 * result is intentionally plain arrays so it can be validated in unit tests
 * and persisted by the organization service without hidden I/O.
 */
final class ClosureBuilder
{
    /**
     * @param  array<int, int|null>  $parents  Child id => parent id (null for root).
     * @return list<array{ancestor_id: int, descendant_id: int, depth: int}>
     */
    public static function build(array $parents): array
    {
        $rows = [];

        foreach (array_keys($parents) as $id) {
            $path = [];
            $cursor = $id;

            while ($cursor !== null) {
                if (in_array($cursor, $path, true)) {
                    throw new InvalidArgumentException('The organization hierarchy contains a cycle.');
                }

                if (! array_key_exists($cursor, $parents)) {
                    throw new InvalidArgumentException("Organization [{$cursor}] has an unknown parent.");
                }

                $path[] = $cursor;
                $cursor = $parents[$cursor];
            }

            $depth = count($path) - 1;

            foreach (array_reverse($path) as $ancestor) {
                $rows[] = ['ancestor_id' => $ancestor, 'descendant_id' => $id, 'depth' => $depth];
                $depth--;
            }
        }

        return $rows;
    }
}
