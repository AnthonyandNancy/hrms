<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

final class PreparedImport
{
    /**
     * @param  list<MigrationRow>  $rows
     * @param  list<RowIssue>  $issues
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $issues,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === \App\Modules\Platform\Migration\Enums\IssueSeverity::ERROR) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    public function failedSourceRows(): array
    {
        $rows = [];

        foreach ($this->issues as $issue) {
            if ($issue->severity === \App\Modules\Platform\Migration\Enums\IssueSeverity::ERROR && $issue->sourceRowNumber !== null) {
                $rows[$issue->sourceRowNumber] = $issue->sourceRowNumber;
            }
        }

        ksort($rows);

        return array_values($rows);
    }
}
