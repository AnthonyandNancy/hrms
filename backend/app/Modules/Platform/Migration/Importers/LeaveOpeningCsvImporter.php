<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Importers;

use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Enums\MigrationDomain;

final class LeaveOpeningCsvImporter extends AbstractCsvImporter
{
    public function domain(): MigrationDomain
    {
        return MigrationDomain::LEAVE_OPENINGS;
    }

    /**
     * @return list<string>
     */
    public function requiredHeaders(): array
    {
        return ['employment_no', 'leave_type', 'quantity_minutes', 'expiry_date'];
    }

    protected function normalizeRow(
        array $values,
        int $sourceRow,
        ReferenceData $references,
        MappingSet $mappings,
        array &$issues,
    ): array {
        $this->validator->requireString($values, 'employment_no', $sourceRow, $issues);
        $this->validator->requireString($values, 'leave_type', $sourceRow, $issues);

        $quantity = $this->validator->integer($values, 'quantity_minutes', $sourceRow, $issues);
        $expiry = $this->validator->date($values, 'expiry_date', $sourceRow, $issues);

        if ($quantity !== null && $quantity < 0) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::NEGATIVE_LEAVE_QUANTITY,
                'Opening leave quantity must be a positive signed GRANT; an opening balance cannot be negative.',
                'quantity_minutes',
            );
        }

        if ($quantity !== null && $quantity > 0 && $quantity % 60 !== 0) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::INVALID_INTEGER,
                'quantity_minutes should be a whole number of minutes for an opening grant.',
                'quantity_minutes',
                IssueSeverity::WARNING,
            );
        }

        $employeeNo = trim($values['employment_no'] ?? '');
        $leaveType = trim($values['leave_type'] ?? '');

        if ($leaveType !== '' && $references->leaveTypes !== [] && ! isset($references->leaveTypes[$leaveType])) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::INVALID_ENUM,
                "Leave type '{$leaveType}' is not present in reference data.",
                'leave_type',
            );
        }

        return [
            'employment_no' => $employeeNo,
            'employment_id' => $mappings->employmentId($employeeNo),
            'leave_type' => $leaveType,
            'account' => 'GRANT',
            'quantity_minutes' => $quantity,
            'expiry_date' => $expiry,
            'business_date' => $expiry,
            'effective_on' => $expiry,
            'source_type' => 'MIGRATION_OPENING',
        ];
    }

    protected function validateCrossRow(
        array $rows,
        ReferenceData $references,
        MappingSet $mappings,
    ): void {
        /** @var array<string, list<int>> $seen */
        $seen = [];

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

            $key = implode('|', [
                $employeeNo,
                (string) $row->data['leave_type'],
                (string) $row->data['expiry_date'],
            ]);
            $seen[$key][] = $row->sourceRowNumber;

            if ($row->data['employment_id'] === null) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::MAPPING_MISSING,
                    'No EMPLOYMENT crosswalk row for leave opening.',
                    'employment_no',
                    IssueSeverity::WARNING,
                );
            }
        }

        foreach ($seen as $key => $sourceRows) {
            if (count($sourceRows) < 2) {
                continue;
            }

            foreach ($sourceRows as $sourceRow) {
                $this->addIssue(
                    $sourceRow,
                    $this->issuesByRow[$sourceRow],
                    IssueCode::DUPLICATE_SOURCE_KEY,
                    "Leave opening '{$key}' appears more than once; append-only GRANTs must be unique per source row.",
                    'employment_no',
                );
            }
        }
    }
}
