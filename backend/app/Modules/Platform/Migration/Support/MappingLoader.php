<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Support;

use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\RowIssue;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use RuntimeException;

/**
 * Loads one or more crosswalk CSVs with headers mapping_type,source_key,target_id.
 * Duplicate source keys and non-positive target ids are hard errors.
 */
final class MappingLoader
{
    /**
     * @param  list<string>  $paths
     * @return array{set: MappingSet, issues: list<RowIssue>}
     */
    public function load(array $paths): array
    {
        $organizations = [];
        $employments = [];
        $issues = [];
        $hashes = [];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                $issues[] = new RowIssue(IssueSeverity::ERROR, IssueCode::FILE_MISSING, "Mapping file is missing: {$path}");

                continue;
            }

            $hash = hash_file('sha256', $path);
            $hashes[] = $hash === false ? '' : $hash;
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                $issues[] = new RowIssue(IssueSeverity::ERROR, IssueCode::FILE_UNREADABLE, "Mapping file is unreadable: {$path}");

                continue;
            }

            try {
                $header = fgetcsv($handle, 0, ',', '"', '\\');

                if ($header === false) {
                    $issues[] = new RowIssue(IssueSeverity::ERROR, IssueCode::FILE_EMPTY, "Mapping file is empty: {$path}");

                    continue;
                }

                $header = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $header);

                foreach (['mapping_type', 'source_key', 'target_id'] as $required) {
                    if (! in_array($required, $header, true)) {
                        $issues[] = new RowIssue(
                            IssueSeverity::ERROR,
                            IssueCode::HEADER_MISSING,
                            "Mapping file '{$path}' is missing header '{$required}'.",
                            sourceRowNumber: 1,
                            field: $required,
                        );
                    }
                }

                $rowNumber = 1;

                while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rowNumber++;

                    if ($values === [null]) {
                        continue;
                    }

                    $row = array_combine($header, array_pad($values, count($header), ''));

                    if ($row === []) {
                        continue;
                    }

                    $type = strtoupper(trim((string) ($row['mapping_type'] ?? '')));
                    $sourceKey = trim((string) ($row['source_key'] ?? ''));
                    $targetId = filter_var(trim((string) ($row['target_id'] ?? '')), FILTER_VALIDATE_INT);

                    if ($sourceKey === '' || ! is_int($targetId) || $targetId <= 0) {
                        $issues[] = new RowIssue(
                            IssueSeverity::ERROR,
                            IssueCode::MAPPING_MISSING,
                            "Mapping row {$rowNumber} needs a non-empty source_key and a positive integer target_id.",
                            sourceRowNumber: $rowNumber,
                        );

                        continue;
                    }

                    if ($type === MappingSet::TYPE_ORGANIZATION) {
                        if (isset($organizations[$sourceKey])) {
                            $issues[] = new RowIssue(
                                IssueSeverity::ERROR,
                                IssueCode::MAPPING_DUPLICATE,
                                "Duplicate ORGANIZATION mapping for '{$sourceKey}'.",
                                sourceRowNumber: $rowNumber,
                                field: 'source_key',
                            );
                        }
                        $organizations[$sourceKey] = $targetId;
                    } elseif ($type === MappingSet::TYPE_EMPLOYMENT) {
                        if (isset($employments[$sourceKey])) {
                            $issues[] = new RowIssue(
                                IssueSeverity::ERROR,
                                IssueCode::MAPPING_DUPLICATE,
                                "Duplicate EMPLOYMENT mapping for '{$sourceKey}'.",
                                sourceRowNumber: $rowNumber,
                                field: 'source_key',
                            );
                        }
                        $employments[$sourceKey] = $targetId;
                    } else {
                        $issues[] = new RowIssue(
                            IssueSeverity::ERROR,
                            IssueCode::MAPPING_MISSING,
                            "Unsupported mapping_type '{$type}'; expected ORGANIZATION or EMPLOYMENT.",
                            sourceRowNumber: $rowNumber,
                            field: 'mapping_type',
                        );
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        sort($hashes);

        return [
            'set' => new MappingSet($organizations, $employments, hash('sha256', implode('|', $hashes))),
            'issues' => $issues,
        ];
    }

    /**
     * @param  list<string>  $paths
     *
     * @throws RuntimeException when the mapping set is invalid
     */
    public function loadOrFail(array $paths): MappingSet
    {
        $result = $this->load($paths);

        foreach ($result['issues'] as $issue) {
            if ($issue->severity === IssueSeverity::ERROR) {
                throw new RuntimeException($issue->message);
            }
        }

        return $result['set'];
    }
}
