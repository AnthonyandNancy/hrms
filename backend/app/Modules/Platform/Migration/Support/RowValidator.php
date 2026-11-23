<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Support;

use App\Modules\Platform\Migration\Dto\ImportValidationResult;
use App\Modules\Platform\Migration\Dto\RowIssue;
use App\Modules\Platform\Migration\Dto\ValidatedRow;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;

/**
 * Validation helpers used by all importers. Every helper is line-oriented so
 * issues can be attributed to the exact source row.
 */
final class RowValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<RowIssue>  $issues
     */
    public function requireString(
        array $data,
        string $field,
        int $sourceRow,
        array &$issues,
        ?int $stgId = null,
    ): void {
        if (! isset($data[$field]) || trim((string) $data[$field]) === '') {
            $issues[] = new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::REQUIRED_FIELD_MISSING,
                "Required field '{$field}' is empty.",
                $stgId,
                $sourceRow,
                $field,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<RowIssue>  $issues
     */
    public function date(
        array $data,
        string $field,
        int $sourceRow,
        array &$issues,
        ?int $stgId = null,
        bool $required = true,
    ): ?string {
        $raw = trim((string) ($data[$field] ?? ''));

        if ($raw === '') {
            if ($required) {
                $issues[] = new RowIssue(
                    IssueSeverity::ERROR,
                    IssueCode::REQUIRED_FIELD_MISSING,
                    "Required date '{$field}' is empty.",
                    $stgId,
                    $sourceRow,
                    $field,
                );
            }

            return null;
        }

        $parsed = ValueParser::date($raw);

        if ($parsed === null) {
            $issues[] = new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::INVALID_DATE,
                "Field '{$field}' must be a valid YYYY-MM-DD date.",
                $stgId,
                $sourceRow,
                $field,
            );
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<RowIssue>  $issues
     */
    public function integer(
        array $data,
        string $field,
        int $sourceRow,
        array &$issues,
        ?int $stgId = null,
    ): ?int {
        $raw = trim((string) ($data[$field] ?? ''));

        if ($raw === '') {
            $issues[] = new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::REQUIRED_FIELD_MISSING,
                "Required integer '{$field}' is empty.",
                $stgId,
                $sourceRow,
                $field,
            );

            return null;
        }

        $parsed = ValueParser::integer($raw);

        if ($parsed === null) {
            $issues[] = new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::INVALID_INTEGER,
                "Field '{$field}' must be a whole number.",
                $stgId,
                $sourceRow,
                $field,
            );
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<RowIssue>  $issues
     */
    public function boolean(
        array $data,
        string $field,
        int $sourceRow,
        array &$issues,
        ?int $stgId = null,
    ): ?bool {
        $raw = trim((string) ($data[$field] ?? ''));
        $parsed = ValueParser::boolean($raw);

        if ($parsed === null) {
            $issues[] = new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::INVALID_BOOLEAN,
                "Field '{$field}' must be true/false, yes/no, or 1/0.",
                $stgId,
                $sourceRow,
                $field,
            );
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     * @param  list<RowIssue>  $issues
     */
    public function enum(
        array $data,
        string $field,
        array $allowed,
        int $sourceRow,
        array &$issues,
        ?int $stgId = null,
    ): void {
        $value = trim((string) ($data[$field] ?? ''));

        if ($value === '') {
            $issues[] = new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::REQUIRED_FIELD_MISSING,
                "Required field '{$field}' is empty.",
                $stgId,
                $sourceRow,
                $field,
            );

            return;
        }

        if (! in_array($value, $allowed, true)) {
            $issues[] = new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::INVALID_ENUM,
                "Field '{$field}' has unsupported value '{$value}'.",
                $stgId,
                $sourceRow,
                $field,
            );
        }
    }

    /**
     * @param  list<RowIssue>  $issues
     */
    public function range(
        string $validFrom,
        string $validTo,
        int $sourceRow,
        array &$issues,
        ?int $stgId = null,
        ?string $fromField = null,
        ?string $toField = null,
    ): void {
        if ($validFrom >= $validTo) {
            $issues[] = new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::INVALID_DATE_RANGE,
                sprintf(
                    '%s must be before %s (half-open interval).',
                    $fromField ?? 'valid_from',
                    $toField ?? 'valid_to',
                ),
                $stgId,
                $sourceRow,
                $fromField ?? 'valid_from',
            );
        }
    }

    /**
     * @param  list<RowIssue>  $issues
     */
    public function decision(
        string $field,
        int $sourceRow,
        array &$issues,
        ?int $stgId = null,
    ): ImportValidationResult {
        return new ImportValidationResult([], $issues);
    }

    /**
     * @param  list<ValidatedRow>  $rows
     * @param  list<RowIssue>  $issues
     */
    public function result(array $rows, array $issues): ImportValidationResult
    {
        return new ImportValidationResult($rows, $issues);
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $headers
     * @param  list<RowIssue>  $issues
     */
    public function headers(array $required, array $headers, array &$issues): void
    {
        foreach ($required as $header) {
            if (! in_array($header, $headers, true)) {
                $issues[] = new RowIssue(
                    IssueSeverity::ERROR,
                    IssueCode::HEADER_MISSING,
                    "Required header '{$header}' is missing.",
                    sourceRowNumber: 1,
                    field: $header,
                );
            }
        }
    }
}
