<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Importers;

use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Dto\ValidatedRow;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Enums\MigrationDomain;

final class OrganizationCsvImporter extends AbstractCsvImporter
{
    private const TYPES = ['COMPANY', 'DIVISION', 'DEPARTMENT', 'TEAM', 'COST_CENTER', 'SHARED_SERVICE'];

    public function domain(): MigrationDomain
    {
        return MigrationDomain::ORGANIZATIONS;
    }

    /**
     * @return list<string>
     */
    public function requiredHeaders(): array
    {
        return ['code', 'name', 'parent_code', 'legal_entity_code', 'type', 'valid_from', 'valid_to'];
    }

    protected function normalizeRow(
        array $values,
        int $sourceRow,
        ReferenceData $references,
        MappingSet $mappings,
        array &$issues,
        int $tenantId,
    ): array {
        foreach (['code', 'name', 'legal_entity_code'] as $field) {
            $this->validator->requireString($values, $field, $sourceRow, $issues);
        }

        $this->validator->enum($values, 'type', self::TYPES, $sourceRow, $issues);
        $validFrom = $this->validator->date($values, 'valid_from', $sourceRow, $issues);
        $validTo = $this->validator->date($values, 'valid_to', $sourceRow, $issues);

        if ($validFrom !== null && $validTo !== null) {
            $this->validator->range($validFrom, $validTo, $sourceRow, $issues);
        }

        $legalEntity = trim($values['legal_entity_code'] ?? '');

        if ($legalEntity !== '' && $references->legalEntityCodes !== [] && ! isset($references->legalEntityCodes[$legalEntity])) {
            $this->addIssue($sourceRow, $issues, IssueCode::ORPHAN_COMPANY, "Legal entity '{$legalEntity}' is not present in reference data.", 'legal_entity_code');
        }

        $code = trim($values['code'] ?? '');

        return [
            'code' => $code,
            'name' => trim($values['name'] ?? ''),
            'parent_code' => trim($values['parent_code'] ?? ''),
            'legal_entity_code' => $legalEntity,
            'legal_entity_id' => null,
            'type' => trim($values['type'] ?? ''),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'mapped_org_id' => $mappings->organizationId($code),
        ];
    }

    protected function validateCrossRow(
        array $rows,
        ReferenceData $references,
        MappingSet $mappings,
        int $tenantId,
    ): void {
        /** @var array<string, list<ValidatedRow>> $byCode */
        $byCode = [];

        foreach ($rows as $row) {
            $code = (string) $row->data['code'];
            if ($code !== '') {
                $byCode[$code][] = $row;
            }
        }

        foreach ($byCode as $code => $duplicates) {
            if (count($duplicates) > 1) {
                foreach ($duplicates as $row) {
                    $this->addIssue(
                        $row->sourceRowNumber,
                        $this->issuesByRow[$row->sourceRowNumber],
                        IssueCode::DUPLICATE_SOURCE_KEY,
                        "Organization code '{$code}' appears more than once.",
                        'code',
                    );
                }
            }
        }

        $knownCodes = array_fill_keys(array_keys($byCode), true) + $references->organizationCodes;

        foreach ($rows as $row) {
            $parent = (string) $row->data['parent_code'];
            if ($parent !== '' && ! isset($knownCodes[$parent])) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::ORPHAN_PARENT,
                    "Parent organization '{$parent}' does not exist in the file or reference data.",
                    'parent_code',
                );
            }
        }

        foreach ($rows as $row) {
            $start = (string) $row->data['code'];
            $visited = [];
            $current = $start;

            while ($current !== '' && isset($byCode[$current]) && ! isset($visited[$current])) {
                $visited[$current] = true;
                $parent = (string) $byCode[$current][0]->data['parent_code'];
                if ($parent === $start) {
                    $this->addIssue(
                        $row->sourceRowNumber,
                        $this->issuesByRow[$row->sourceRowNumber],
                        IssueCode::ORGANIZATION_CYCLE,
                        "Organization '{$start}' participates in a parent/child cycle.",
                        'parent_code',
                    );
                    break;
                }
                $current = $parent;
            }
        }

        // A mapped target id must be a real positive integer; mapping loader
        // already enforces this. Missing mappings are warnings, not inventions.
        foreach ($rows as $row) {
            if ($row->data['mapped_org_id'] === null) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::MAPPING_MISSING,
                    'No ORGANIZATION crosswalk row; database adapter will resolve by code or leave linkage pending.',
                    'code',
                    IssueSeverity::WARNING,
                );
            }
        }
    }
}
