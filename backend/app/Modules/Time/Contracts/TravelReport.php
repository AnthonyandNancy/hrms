<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Stable read-only report shape exposed outside the Time module. The rows are
 * already scoped by TravelReportService; consumers must not re-aggregate or
 * infer per-employee attendance from them.
 */
final readonly class TravelReport
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        public string $asOf,
        public string $sourceHash,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, as_of: string, source_hash: string}
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'total' => $this->total,
            'as_of' => $this->asOf,
            'source_hash' => $this->sourceHash,
        ];
    }
}
