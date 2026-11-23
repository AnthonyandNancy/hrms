<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Support;

use App\Modules\Platform\Migration\Dto\CsvDocument;
use App\Modules\Platform\Migration\Dto\CsvRow;
use App\Modules\Platform\Migration\Dto\RowIssue;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;

/**
 * Reads RFC4180-style CSV without touching target tables. Source row numbers
 * are 1-based and include the header row, which is what operators see in Excel.
 */
final class CsvReader
{
    public function read(string $path): CsvDocument
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new CsvDocument([], [], [
                new RowIssue(IssueSeverity::ERROR, IssueCode::FILE_MISSING, "Migration file is missing or unreadable: {$path}"),
            ], '');
        }

        $hash = hash_file('sha256', $path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return new CsvDocument([], [], [
                new RowIssue(IssueSeverity::ERROR, IssueCode::FILE_UNREADABLE, "Could not open migration file: {$path}"),
            ], $hash === false ? '' : $hash);
        }

        try {
            $rawHeader = fgetcsv($handle, 0, ',', '"', '\\');
            if ($rawHeader === false || $rawHeader === [null]) {
                return new CsvDocument([], [], [
                    new RowIssue(IssueSeverity::ERROR, IssueCode::FILE_EMPTY, 'Migration file is empty.'),
                ], $hash === false ? '' : $hash);
            }

            $headers = [];
            foreach ($rawHeader as $column => $header) {
                $normalized = strtolower(trim($this->stripBom((string) $header)));
                if ($normalized === '') {
                    continue;
                }

                if (in_array($normalized, $headers, true)) {
                    return new CsvDocument([], [], [
                        new RowIssue(
                            IssueSeverity::ERROR,
                            IssueCode::HEADER_MISSING,
                            "Duplicate CSV header '{$normalized}' at column ".($column + 1).'.',
                            sourceRowNumber: 1,
                            field: $normalized,
                        ),
                    ], $hash === false ? '' : $hash);
                }

                $headers[] = $normalized;
            }

            if ($headers === []) {
                return new CsvDocument([], [], [
                    new RowIssue(IssueSeverity::ERROR, IssueCode::HEADER_MISSING, 'The CSV header row has no columns.'),
                ], $hash === false ? '' : $hash);
            }

            $rows = [];
            $issues = [];
            $sourceRow = 1;

            while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $sourceRow++;

                if ($values === [null]) {
                    continue;
                }

                if (count($values) !== count($headers)) {
                    $issues[] = new RowIssue(
                        IssueSeverity::ERROR,
                        IssueCode::ROW_COLUMN_COUNT_MISMATCH,
                        sprintf('Row has %d columns; header has %d.', count($values), count($headers)),
                        sourceRowNumber: $sourceRow,
                    );
                }

                $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
                $map = [];

                foreach ($headers as $index => $header) {
                    $value = $values[$index] ?? '';
                    $map[$header] = is_string($value) ? trim($value) : (string) $value;
                }

                if (array_filter($map, static fn (string $value): bool => $value !== '') === []) {
                    continue;
                }

                $rows[] = new CsvRow($sourceRow, $map);
            }

            if ($sourceRow === 1) {
                $issues[] = new RowIssue(IssueSeverity::ERROR, IssueCode::FILE_EMPTY, 'Migration file has a header but no data rows.');
            }

            return new CsvDocument($headers, $rows, $issues, $hash === false ? '' : $hash);
        } finally {
            fclose($handle);
        }
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
