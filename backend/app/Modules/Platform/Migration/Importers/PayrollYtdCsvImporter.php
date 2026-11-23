<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Importers;

use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Dto\ValidatedRow;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Enums\MigrationDomain;

final class PayrollYtdCsvImporter extends AbstractCsvImporter
{
    private const AMOUNT_FIELDS = [
        'taxable' => 'taxable_cents',
        'tax_withheld' => 'tax_withheld_cents',
        'si' => 'si_cents',
        'hf' => 'hf_cents',
        'gross' => 'gross_cents',
        'net' => 'net_cents',
    ];

    public function domain(): MigrationDomain
    {
        return MigrationDomain::PAYROLL_YTD;
    }

    /**
     * @return list<string>
     */
    public function requiredHeaders(): array
    {
        return ['employment_no', 'tax_year', 'taxable', 'tax_withheld', 'si', 'hf', 'gross', 'net'];
    }

    protected function normalizeRow(
        array $values,
        int $sourceRow,
        ReferenceData $references,
        MappingSet $mappings,
        array &$issues,
        int $tenantId,
    ): array {
        $this->validator->requireString($values, 'employment_no', $sourceRow, $issues);
        $taxYear = $this->validator->integer($values, 'tax_year', $sourceRow, $issues);

        if ($taxYear !== null && ($taxYear < 1900 || $taxYear > 2200)) {
            $this->addIssue($sourceRow, $issues, IssueCode::INVALID_INTEGER, 'tax_year must be between 1900 and 2200.', 'tax_year');
        }

        $data = [
            'employment_no' => trim($values['employment_no'] ?? ''),
            'tax_year' => $taxYear,
            'source_type' => 'MIGRATION',
        ];

        foreach (self::AMOUNT_FIELDS as $sourceField => $targetField) {
            $amount = $this->validator->integer($values, $sourceField, $sourceRow, $issues);

            if ($amount !== null && $amount < 0) {
                $this->addIssue(
                    $sourceRow,
                    $issues,
                    IssueCode::NEGATIVE_PAYROLL_AMOUNT,
                    "Payroll YTD amount '{$sourceField}' cannot be negative in an opening balance.",
                    $sourceField,
                );
            }

            $data[$targetField] = $amount;
        }

        if (is_int($data['gross_cents']) && is_int($data['net_cents']) && $data['net_cents'] > $data['gross_cents']) {
            $this->addIssue(
                $sourceRow,
                $issues,
                IssueCode::PAYROLL_NET_MISMATCH,
                'Net YTD cannot exceed gross YTD.',
                'net',
            );
        }

        $employeeNo = (string) $data['employment_no'];

        return [
            ...$data,
            'employment_id' => $mappings->employmentId($employeeNo),
        ];
    }

    protected function validateCrossRow(
        array $rows,
        ReferenceData $references,
        MappingSet $mappings,
        int $tenantId,
    ): void {
        /** @var array<string, list<ValidatedRow>> $byEmploymentYear */
        $byEmploymentYear = [];

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

            $byEmploymentYear[$employeeNo.'|'.(string) $row->data['tax_year']][] = $row;

            if ($row->data['employment_id'] === null) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::MAPPING_MISSING,
                    'No EMPLOYMENT crosswalk row for payroll YTD.',
                    'employment_no',
                    IssueSeverity::WARNING,
                );
            }
        }

        foreach ($byEmploymentYear as $key => $duplicates) {
            if (count($duplicates) < 2) {
                continue;
            }

            foreach ($duplicates as $row) {
                $this->addIssue(
                    $row->sourceRowNumber,
                    $this->issuesByRow[$row->sourceRowNumber],
                    IssueCode::DUPLICATE_SOURCE_KEY,
                    "Payroll YTD opening '{$key}' appears more than once.",
                    'tax_year',
                );
            }
        }
    }
}
