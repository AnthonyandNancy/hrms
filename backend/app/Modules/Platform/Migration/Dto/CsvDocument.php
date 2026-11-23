<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

/**
 * Parsed CSV content. The header is lower-cased and trimmed; duplicate headers
 * are rejected by CsvReader rather than silently overwritten.
 */
final class CsvDocument
{
    /**
     * @param  list<string>  $headers
     * @param  list<CsvRow>  $rows
     * @param  list<RowIssue>  $issues
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly array $issues,
        public readonly string $fileHash,
    ) {}
}
