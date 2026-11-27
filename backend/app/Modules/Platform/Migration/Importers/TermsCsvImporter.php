<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Importers;

use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Dto\ValidatedRow;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Enums\MigrationDomain;

final class TermsCsvImporter extends AbstractCsvImporter
{
    public function domain(): MigrationDomain
    {
        return MigrationDomain::TERMS;
    }

    /**
     * @return list<string>
     */
    public function requiredHeaders(): array
    {
        return ['employment_no', 'version', 'start_date', 'end_date', 'salary_scheme_code', 'probation_end_date', 'work_location', 'work_schedule', 'base_salary_minor', 'salary_currency'];
    }

    protected function normalizeRow(
        array $values,
        int $sourceRow,
        ReferenceData $references,
        MappingSet $mappings,
        array &$issues,
    ): array {
        $this->validator->requireString($values, 'employment_no', $sourceRow, $issues);
        $this->validator->requireString($values, 'salary_scheme_code', $sourceRow, $issues);
        // Both are NOT NULL contract facts in People's frozen schema; the
        // legacy extract must supply them rather than have the adapter guess.
        $this->validator->requireString($values, 'work_location', $sourceRow, $issues);
        $this->validator->requireString($values, 'work_schedule', $sourceRow, $issues);
        $this->validator->requireString($values, 'salary_currency', $sourceRow, $issues);
        $baseSalary = $this->validator->integer($values, 'base_salary_minor', $sourceRow, $issues);

        if ($baseSalary !== null && $baseSalary < 0) {
            $this->addIssue($sourceRow, $issues, IssueCode::INVALID_INTEGER, 'base_salary_minor cannot be negative.', 'base_salary_minor');
        }

        $version = $this->validator->integer($values, 'version', $sourceRow, $issues);
        $start = $this->validator->date($values, 'start_date', $sourceRow, $issues);
        $end = $this->validator->date($values, 'end_date', $sourceRow, $issues);
        $probationEnd = $this->validator->date($values, 'probation_end_date', $sourceRow, $issues, required: false);

        if ($version !== null && $version <= 0) {
            $this->addIssue($sourceRow, $issues, IssueCode::INVALID_INTEGER, 'version must be a positive integer.', 'version');
        }

        if ($start !== null && $end !== null) {
            $this->validator->range($start, $end, $sourceRow, $issues, fromField: 'start_date', toField: 'end_date');
        }

        if ($probationEnd !== null && $start !== null && $probationEnd < $start) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::INVALID_DATE_RANGE,
                'probation_end_date cannot be before start_date.',
                'probation_end_date',
            );
        }

        $employeeNo = trim($values['employment_no'] ?? '');

        return [
            'employment_no' => $employeeNo,
            'employment_id' => $mappings->employmentId($employeeNo),
            'version' => $version,
            'start_date' => $start,
            'end_date' => $end,
            'effective_from' => $start,
            'effective_to' => $end,
            'salary_scheme_code' => trim($values['salary_scheme_code'] ?? ''),
            'probation_end_date' => $probationEnd,
            'work_location' => trim($values['work_location'] ?? ''),
            'work_schedule' => trim($values['work_schedule'] ?? ''),
            'base_salary_minor' => $baseSalary,
            'salary_currency' => trim($values['salary_currency'] ?? ''),
            'source_type' => 'MIGRATION',
        ];
    }

    protected function validateCrossRow(
        array $rows,
        ReferenceData $references,
        MappingSet $mappings,
    ): void {
        /** @var array<string, list<ValidatedRow>> $byEmploymentVersion */
        $byEmploymentVersion = [];

        foreach ($rows as $row) {
            $employeeNo = (string) $row->data['employment_no'];

            if ($references->employeeNumbers !== [] && ! isset($references->employeeNumbers[$employeeNo])) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::ORPHAN_EMPLOYMENT,
                    "Employment '{$employeeNo}' is not present in reference data.",
                    'employment_no',
                );
            }

            $key = $employeeNo.'|'.(string) $row->data['version'];
            $byEmploymentVersion[$key][] = $row;

            if ($row->data['employment_id'] === null) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::MAPPING_MISSING,
                    'No EMPLOYMENT crosswalk row for terms.',
                    'employment_no',
                    IssueSeverity::WARNING,
                );
            }
        }

        foreach ($byEmploymentVersion as $key => $duplicates) {
            if (count($duplicates) < 2) {
                continue;
            }

            foreach ($duplicates as $row) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::TERMS_VERSION_CONFLICT,
                    "Employment terms version '{$key}' is duplicated.",
                    'version',
                );
            }
        }

        // Versions for one employment must not overlap; the business table
        // carries an as-of invariant even where a UNIQUE key only covers version.
        /** @var array<string, list<ValidatedRow>> $byEmployment */
        $byEmployment = [];

        foreach ($rows as $row) {
            $byEmployment[(string) $row->data['employment_no']][] = $row;
        }

        foreach ($byEmployment as $employeeNo => $terms) {
            usort($terms, static fn (ValidatedRow $a, ValidatedRow $b): int => $a->data['effective_from'] <=> $b->data['effective_from']);

            for ($i = 1; $i < count($terms); $i++) {
                $previous = $terms[$i - 1];
                $current = $terms[$i];

                if ((string) $previous->data['effective_to'] > (string) $current->data['effective_from']) {
                    foreach ([$previous->sourceRowNumber, $current->sourceRowNumber] as $sourceRow) {
                        $this->addIssue(
                            $sourceRow,
                            $this->issuesByRow[$sourceRow],
                            IssueCode::TERMS_VERSION_CONFLICT,
                            "Terms for employment '{$employeeNo}' overlap in effective dates.",
                            'start_date',
                        );
                    }
                }
            }
        }
    }
}
