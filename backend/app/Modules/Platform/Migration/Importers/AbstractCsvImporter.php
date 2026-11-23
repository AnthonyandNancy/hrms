<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Importers;

use App\Modules\Platform\Migration\Contracts\CsvDomainImporter;
use App\Modules\Platform\Migration\Dto\CsvDocument;
use App\Modules\Platform\Migration\Dto\ImportValidationResult;
use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Dto\RowIssue;
use App\Modules\Platform\Migration\Dto\ValidatedRow;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Support\RowValidator;

abstract class AbstractCsvImporter implements CsvDomainImporter
{
    /** @var array<int, list<RowIssue>> */
    protected array $issuesByRow = [];

    public function __construct(protected readonly RowValidator $validator) {}

    final public function validate(
        CsvDocument $document,
        ReferenceData $references,
        MappingSet $mappings,
        int $tenantId,
    ): ImportValidationResult {
        $this->issuesByRow = [];
        $issues = $document->issues;

        $this->validator->headers($this->requiredHeaders(), $document->headers, $issues);

        if ($issues !== []) {
            return new ImportValidationResult([], $issues);
        }

        $rows = [];

        foreach ($document->rows as $csvRow) {
            $this->issuesByRow[$csvRow->sourceRowNumber] = [];
            $normalized = $this->normalizeRow(
                $csvRow->values,
                $csvRow->sourceRowNumber,
                $references,
                $mappings,
                $this->issuesByRow[$csvRow->sourceRowNumber],
                $tenantId,
            );
            $rows[] = new ValidatedRow($csvRow->sourceRowNumber, $normalized);
        }

        $this->validateCrossRow($rows, $references, $mappings, $tenantId);

        foreach ($this->issuesByRow as $rowIssues) {
            $issues = [...$issues, ...$rowIssues];
        }

        $validRows = [];

        foreach ($rows as $row) {
            $hasError = false;

            foreach ($this->issuesByRow[$row->sourceRowNumber] ?? [] as $issue) {
                if ($issue->severity === IssueSeverity::ERROR) {
                    $hasError = true;
                    break;
                }
            }

            if (! $hasError) {
                $validRows[] = $row;
            }
        }

        return new ImportValidationResult($validRows, $issues);
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<RowIssue>  $issues
     * @return array<string, mixed>
     */
    abstract protected function normalizeRow(
        array $values,
        int $sourceRow,
        ReferenceData $references,
        MappingSet $mappings,
        array &$issues,
        int $tenantId,
    ): array;

    /**
     * Second pass checks that need all rows (cycles, manager resolution,
     * overlap detection, duplicate versions).
     *
     * @param  list<ValidatedRow>  $rows
     */
    protected function validateCrossRow(
        array $rows,
        ReferenceData $references,
        MappingSet $mappings,
        int $tenantId,
    ): void {}

    /**
     * @param  list<RowIssue>  $issues
     */
    protected function addIssue(
        int $sourceRow,
        array &$issues,
        \App\Modules\Platform\Migration\Enums\IssueCode $code,
        string $message,
        ?string $field = null,
        ?IssueSeverity $severity = null,
    ): void {
        $issues[] = new RowIssue(
            $severity ?? IssueSeverity::ERROR,
            $code,
            $message,
            sourceRowNumber: $sourceRow,
            field: $field,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function normalizeDateOrNull(array $row, string $field): ?string
    {
        $value = trim((string) ($row[$field] ?? ''));

        return $value === '' ? null : $value;
    }
}
