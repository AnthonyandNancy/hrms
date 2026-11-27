<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Importers;

use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Dto\ValidatedRow;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Enums\MigrationDomain;

final class AssignmentCsvImporter extends AbstractCsvImporter
{
    public function domain(): MigrationDomain
    {
        return MigrationDomain::ASSIGNMENTS;
    }

    /**
     * @return list<string>
     */
    public function requiredHeaders(): array
    {
        return ['employment_no', 'org_code', 'position_code', 'manager_employee_no', 'valid_from', 'valid_to', 'is_primary'];
    }

    protected function normalizeRow(
        array $values,
        int $sourceRow,
        ReferenceData $references,
        MappingSet $mappings,
        array &$issues,
    ): array {
        foreach (['employment_no', 'org_code', 'position_code'] as $field) {
            $this->validator->requireString($values, $field, $sourceRow, $issues);
        }

        $validFrom = $this->validator->date($values, 'valid_from', $sourceRow, $issues);
        $validTo = $this->validator->date($values, 'valid_to', $sourceRow, $issues);
        $isPrimary = $this->validator->boolean($values, 'is_primary', $sourceRow, $issues);

        if ($validFrom !== null && $validTo !== null) {
            $this->validator->range($validFrom, $validTo, $sourceRow, $issues);
        }

        $employeeNo = trim($values['employment_no'] ?? '');
        $orgCode = trim($values['org_code'] ?? '');
        $manager = trim($values['manager_employee_no'] ?? '');

        return [
            'employment_no' => $employeeNo,
            'employment_id' => $mappings->employmentId($employeeNo),
            'org_code' => $orgCode,
            'org_unit_id' => $mappings->organizationId($orgCode),
            'position_code' => trim($values['position_code'] ?? ''),
            'manager_employee_no' => $manager,
            'manager_employment_id' => $manager === '' ? null : $mappings->employmentId($manager),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'is_primary' => $isPrimary ?? false,
            'assignment_type' => $isPrimary === true ? 'PRIMARY' : 'SECONDMENT',
        ];
    }

    protected function validateCrossRow(
        array $rows,
        ReferenceData $references,
        MappingSet $mappings,
    ): void {
        $employeeNumbers = $references->employeeNumbers;

        foreach ($rows as $row) {
            $employeeNo = (string) $row->data['employment_no'];

            if ($employeeNumbers !== [] && ! isset($employeeNumbers[$employeeNo])) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::ORPHAN_EMPLOYMENT,
                    "Employment '{$employeeNo}' is not present in reference data.",
                    'employment_no',
                );
            }

            $orgCode = (string) $row->data['org_code'];

            if ($references->organizationCodes !== [] && ! isset($references->organizationCodes[$orgCode])) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::ORPHAN_ORGANIZATION,
                    "Organization '{$orgCode}' is not present in reference data.",
                    'org_code',
                );
            }

            // Two-pass manager resolution: only classify missing managers once
            // all rows are known, never during the row loop above.
            $manager = (string) $row->data['manager_employee_no'];

            if ($manager !== '' && $employeeNumbers !== [] && ! isset($employeeNumbers[$manager])) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::ORPHAN_MANAGER,
                    "Manager employment '{$manager}' is not present in reference data.",
                    'manager_employee_no',
                );
            }

            if ($row->data['employment_id'] === null) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::MAPPING_MISSING,
                    'No EMPLOYMENT crosswalk row for assignment.',
                    'employment_no',
                    IssueSeverity::WARNING,
                );
            }

            if ($row->data['org_unit_id'] === null) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::MAPPING_MISSING,
                    'No ORGANIZATION crosswalk row for assignment.',
                    'org_code',
                    IssueSeverity::WARNING,
                );
            }
        }

        /** @var array<string, list<ValidatedRow>> $primaryByEmployment */
        $primaryByEmployment = [];

        foreach ($rows as $row) {
            if ($row->data['is_primary'] === true) {
                $primaryByEmployment[(string) $row->data['employment_no']][] = $row;
            }
        }

        foreach ($primaryByEmployment as $employeeNo => $primaryRows) {
            foreach ($primaryRows as $index => $left) {
                foreach ($primaryRows as $rightIndex => $right) {
                    if ($rightIndex <= $index) {
                        continue;
                    }

                    if ($this->overlaps($left, $right)) {
                        foreach ([$left->sourceRowNumber, $right->sourceRowNumber] as $sourceRow) {
                            $this->addIssue(
                                $sourceRow,
                                $this->issuesByRow[$sourceRow],
                                IssueCode::OVERLAPPING_PRIMARY_ASSIGNMENT,
                                "PRIMARY assignments overlap for employment '{$employeeNo}'.",
                                'valid_from',
                            );
                        }
                    }
                }
            }
        }
    }

    private function overlaps(ValidatedRow $left, ValidatedRow $right): bool
    {
        $leftFrom = (string) $left->data['valid_from'];
        $leftTo = (string) $left->data['valid_to'];
        $rightFrom = (string) $right->data['valid_from'];
        $rightTo = (string) $right->data['valid_to'];

        return $leftFrom < $rightTo && $rightFrom < $leftTo;
    }
}
