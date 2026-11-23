<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

/**
 * Read-only lookups assembled from sibling fixture files or staging rows.
 * Missing reference data is "unknown", never permission to invent a target row.
 */
final class ReferenceData
{
    /**
     * @param  array<string, true>  $organizationCodes
     * @param  array<string, true>  $employeeNumbers
     * @param  array<string, true>  $legalEntityCodes
     * @param  array<string, true>  $leaveTypes
     */
    public function __construct(
        public readonly array $organizationCodes = [],
        public readonly array $employeeNumbers = [],
        public readonly array $legalEntityCodes = [],
        public readonly array $leaveTypes = [],
    ) {}

    public function hasOrganizations(): bool
    {
        return $this->organizationCodes !== [];
    }

    public function hasPeople(): bool
    {
        return $this->employeeNumbers !== [];
    }

    public static function withDefaults(string $fixtureDirectory): self
    {
        $reader = new \App\Modules\Platform\Migration\Support\CsvReader;

        $organizationCodes = [];
        $employeeNumbers = [];
        $legalEntities = [];
        $leaveTypes = [];

        $organizations = $reader->read($fixtureDirectory.'/organizations.csv');
        foreach ($organizations->rows as $row) {
            $code = trim($row->values['code'] ?? '');
            if ($code !== '') {
                $organizationCodes[$code] = true;
            }
            $legalEntity = trim($row->values['legal_entity_code'] ?? '');
            if ($legalEntity !== '') {
                $legalEntities[$legalEntity] = true;
            }
        }

        $people = $reader->read($fixtureDirectory.'/people.csv');
        foreach ($people->rows as $row) {
            $employeeNo = trim($row->values['employee_no'] ?? '');
            if ($employeeNo !== '') {
                $employeeNumbers[$employeeNo] = true;
            }
            $legalEntity = trim($row->values['legal_entity_code'] ?? '');
            if ($legalEntity !== '') {
                $legalEntities[$legalEntity] = true;
            }
        }

        $leave = $reader->read($fixtureDirectory.'/leave_openings.csv');
        foreach ($leave->rows as $row) {
            $type = trim($row->values['leave_type'] ?? '');
            if ($type !== '') {
                $leaveTypes[$type] = true;
            }
        }

        if ($legalEntities === []) {
            $legalEntities['DEMO-CN'] = true;
        }

        if ($leaveTypes === []) {
            $leaveTypes = ['ANNUAL' => true, 'SICK' => true, 'PERSONAL' => true];
        }

        return new self($organizationCodes, $employeeNumbers, $legalEntities, $leaveTypes);
    }
}
