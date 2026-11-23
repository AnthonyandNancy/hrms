<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Importers;

use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Dto\ValidatedRow;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use Illuminate\Support\Facades\Crypt;

final class PeopleCsvImporter extends AbstractCsvImporter
{
    private const EMPLOYMENT_TYPES = ['FULL_TIME', 'PART_TIME', 'FIXED_TERM', 'INTERN', 'CONTRACTOR'];

    private const STATUSES = ['PROBATION', 'ACTIVE', 'LEAVE', 'SUSPENDED', 'TERMINATED'];

    public function domain(): MigrationDomain
    {
        return MigrationDomain::PEOPLE;
    }

    /**
     * @return list<string>
     */
    public function requiredHeaders(): array
    {
        return [
            'person_key',
            'id_type',
            'id_number',
            'legal_name',
            'birth_date',
            'employee_no',
            'legal_entity_code',
            'hire_date',
            'employment_type',
            'status',
            'termination_date',
        ];
    }

    protected function normalizeRow(
        array $values,
        int $sourceRow,
        ReferenceData $references,
        MappingSet $mappings,
        array &$issues,
        int $tenantId,
    ): array {
        foreach (['person_key', 'id_type', 'id_number', 'legal_name', 'employee_no', 'legal_entity_code'] as $field) {
            $this->validator->requireString($values, $field, $sourceRow, $issues);
        }

        $this->validator->enum($values, 'employment_type', self::EMPLOYMENT_TYPES, $sourceRow, $issues);
        $this->validator->enum($values, 'status', self::STATUSES, $sourceRow, $issues);
        $birthDate = $this->validator->date($values, 'birth_date', $sourceRow, $issues);
        $hireDate = $this->validator->date($values, 'hire_date', $sourceRow, $issues);
        $terminationDate = $this->validator->date($values, 'termination_date', $sourceRow, $issues, required: false);

        if ($hireDate !== null && $terminationDate !== null && $hireDate >= $terminationDate) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::INVALID_DATE_RANGE,
                'termination_date must be after hire_date.',
                'termination_date',
            );
        }

        $status = trim($values['status'] ?? '');

        if ($status === 'TERMINATED' && $terminationDate === null) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::EMPLOYMENT_STATUS_CONFLICT,
                'A TERMINATED employment requires termination_date.',
                'termination_date',
            );
        }

        if ($status !== 'TERMINATED' && $terminationDate !== null) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::EMPLOYMENT_STATUS_CONFLICT,
                'termination_date is only valid for TERMINATED status.',
                'termination_date',
            );
        }

        $idType = trim($values['id_type'] ?? '');
        $idNumber = trim($values['id_number'] ?? '');
        $legalEntity = trim($values['legal_entity_code'] ?? '');

        if ($legalEntity !== '' && $references->legalEntityCodes !== [] && ! isset($references->legalEntityCodes[$legalEntity])) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::ORPHAN_LEGAL_ENTITY,
                "Legal entity '{$legalEntity}' is not present in reference data.",
                'legal_entity_code',
            );
        }

        $idHash = $idNumber === '' ? '' : hash_hmac('sha256', $idType.'|'.$idNumber, (string) config('app.key'));

        return [
            'person_key' => trim($values['person_key'] ?? ''),
            'id_type' => $idType,
            'id_number_hash' => $idHash,
            'id_number_ciphertext' => $idNumber === '' ? null : Crypt::encryptString($idNumber),
            'legal_name' => trim($values['legal_name'] ?? ''),
            'birth_date' => $birthDate,
            'employee_no' => trim($values['employee_no'] ?? ''),
            'legal_entity_code' => $legalEntity,
            'legal_entity_id' => null,
            'hire_date' => $hireDate,
            'employment_type' => trim($values['employment_type'] ?? ''),
            'status' => $status,
            'termination_date' => $terminationDate,
            'valid_from' => $hireDate,
            'valid_to' => $terminationDate ?? '9999-12-31',
            'mapped_employment_id' => $mappings->employmentId(trim($values['employee_no'] ?? '')),
        ];
    }

    protected function validateCrossRow(
        array $rows,
        ReferenceData $references,
        MappingSet $mappings,
        int $tenantId,
    ): void {
        /** @var array<string, list<ValidatedRow>> $byEmployeeNo */
        $byEmployeeNo = [];
        /** @var array<string, list<ValidatedRow>> $byIdentity */
        $byIdentity = [];

        foreach ($rows as $row) {
            $employeeNo = (string) $row->data['employee_no'];

            if ($employeeNo !== '') {
                $byEmployeeNo[$employeeNo][] = $row;
            }

            if ($row->data['id_number_hash'] !== '') {
                $identity = (string) $row->data['id_number_hash'].'|'.$employeeNo;
                $byIdentity[$identity][] = $row;
            }
        }

        foreach ($byEmployeeNo as $employeeNo => $duplicates) {
            if (count($duplicates) > 1) {
                foreach ($duplicates as $row) {
                    $this->addIssue(
                        $row->sourceRowNumber,
                        $this->issuesByRow[$row->sourceRowNumber],
                        IssueCode::DUPLICATE_EMPLOYEE_NO,
                        "Employee number '{$employeeNo}' appears more than once.",
                        'employee_no',
                    );
                }
            }
        }

        foreach ($byIdentity as $duplicates) {
            if (count($duplicates) > 1) {
                foreach ($duplicates as $row) {
                    $this->addIssue(
                        $row->sourceRowNumber,
                        $this->issuesByRow[$row->sourceRowNumber],
                        IssueCode::DUPLICATE_PERSON,
                        'The same identity and employee number appear more than once.',
                        'id_number',
                    );
                }
            }
        }

        foreach ($rows as $row) {
            if ($row->data['mapped_employment_id'] === null) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::MAPPING_MISSING,
                    'No EMPLOYMENT crosswalk row; database adapter will resolve by employee number or leave linkage pending.',
                    'employee_no',
                    IssueSeverity::WARNING,
                );
            }
        }
    }
}
