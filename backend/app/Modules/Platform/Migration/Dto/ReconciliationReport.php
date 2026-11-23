<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

final class ReconciliationReport
{
    /**
     * @param  array<string, int>  $sourceSums
     * @param  array<string, int>  $targetSums
     * @param  array<string, int>  $differences
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $domain,
        public readonly int $sourceCount,
        public readonly int $targetCount,
        public readonly array $sourceSums,
        public readonly array $targetSums,
        public readonly array $differences,
        public readonly bool $passed,
        public readonly string $generatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'domain' => $this->domain,
            'source_count' => $this->sourceCount,
            'target_count' => $this->targetCount,
            'source_sums' => $this->sourceSums,
            'target_sums' => $this->targetSums,
            'differences' => $this->differences,
            'passed' => $this->passed,
            'generated_at' => $this->generatedAt,
        ];
    }

    public function humanTable(): string
    {
        $lines = [
            sprintf('Reconciliation for run %d (%s)', $this->runId, $this->domain),
            sprintf('  %-24s %-16s %-16s %-12s', 'metric', 'source', 'target', 'difference'),
            sprintf('  %-24s %-16d %-16d %-12d', 'count', $this->sourceCount, $this->targetCount, $this->targetCount - $this->sourceCount),
        ];

        foreach ($this->sourceSums as $metric => $source) {
            $target = $this->targetSums[$metric] ?? 0;
            $lines[] = sprintf('  %-24s %-16d %-16d %-12d', $metric, $source, $target, $target - $source);
        }

        $lines[] = $this->passed ? '  RESULT: PASS' : '  RESULT: FAIL';

        return implode(PHP_EOL, $lines);
    }
}
