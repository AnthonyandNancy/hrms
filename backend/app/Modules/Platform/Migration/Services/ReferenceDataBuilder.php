<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Services;

use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Models\StagingRow;
use App\Modules\Platform\Migration\Support\CsvReader;
use Illuminate\Support\Facades\DB;

/**
 * Builds reference lookups from on-disk sibling fixtures or from prior staging
 * runs. The framework never invents codes; a missing organization remains an
 * orphan issue until an operator imports the organization domain.
 */
final class ReferenceDataBuilder
{
    public function __construct(private readonly CsvReader $reader) {}

    public function build(MigrationDomain $domain, string $fixtureDirectory, MappingSet $mappings): ReferenceData
    {
        $organizationCodes = [];
        $employeeNumbers = [];
        $leaveTypes = ['ANNUAL' => true, 'SICK' => true, 'PERSONAL' => true];
        $orgFile = $fixtureDirectory.'/organizations.csv';
        $peopleFile = $fixtureDirectory.'/people.csv';

        if (is_file($orgFile)) {
            foreach ($this->reader->read($orgFile)->rows as $row) {
                $code = trim($row->values['code'] ?? '');
                if ($code !== '') {
                    $organizationCodes[$code] = true;
                }
            }
        }

        if (is_file($peopleFile)) {
            foreach ($this->reader->read($peopleFile)->rows as $row) {
                $employeeNo = trim($row->values['employee_no'] ?? '');
                if ($employeeNo !== '') {
                    $employeeNumbers[$employeeNo] = true;
                }
            }
        }

        // Staging rows from earlier successful runs win when no fixture sibling
        // exists (production dry-run uses uploaded CSVs only).
        if ($organizationCodes === [] && DB::connection()->getSchemaBuilder()->hasTable('stg_organizations')) {
            $rows = StagingRow::forDomain(MigrationDomain::ORGANIZATIONS)
                ->newQuery()
                ->whereIn('run_id', $this->successfulRunIds(MigrationDomain::ORGANIZATIONS))
                ->get();
            foreach ($rows as $row) {
                $data = $row->normalized_json ?? $row->raw_json;
                $code = trim((string) ($data['code'] ?? ''));
                if ($code !== '') {
                    $organizationCodes[$code] = true;
                }
            }
        }

        if ($employeeNumbers === [] && DB::connection()->getSchemaBuilder()->hasTable('stg_people')) {
            $rows = StagingRow::forDomain(MigrationDomain::PEOPLE)
                ->newQuery()
                ->whereIn('run_id', $this->successfulRunIds(MigrationDomain::PEOPLE))
                ->get();
            foreach ($rows as $row) {
                $data = $row->normalized_json ?? $row->raw_json;
                $employeeNo = trim((string) ($data['employee_no'] ?? ''));
                if ($employeeNo !== '') {
                    $employeeNumbers[$employeeNo] = true;
                }
            }
        }

        return new ReferenceData($organizationCodes, $employeeNumbers, $leaveTypes);
    }

    /**
     * @return list<int>
     */
    private function successfulRunIds(MigrationDomain $domain): array
    {
        return MigrationRun::query()
            ->where('domain', $domain->value)
            ->whereIn('status', ['VALIDATED', 'APPLIED'])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
