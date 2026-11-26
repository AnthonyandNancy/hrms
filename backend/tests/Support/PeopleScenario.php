<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Modules\Organization\Models\Position;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Document;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\EmploymentUserLink;
use App\Modules\People\Models\Person;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic single-company fixtures for People and downstream module tests.
 * There is exactly one company; organization units are the scope dimension.
 */
final class PeopleScenario
{
    public function __construct(private readonly Clock $clock) {}

    public function orgUnit(
        string $code,
        ?OrgUnit $parent = null,
        string $name = '',
        string $type = 'DEPARTMENT',
        string $validFrom = '2026-01-01',
        string $validTo = '9999-12-31',
    ): OrgUnit {
        $recordedAt = $this->clock->now();
        $unit = OrgUnit::query()->create([
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $name !== '' ? $name : $code,
            'type' => $type,
            'status' => 'ACTIVE',
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'recorded_at' => $recordedAt,
            'source_type' => 'TEST',
        ]);

        $rows = [[
            'ancestor_id' => $unit->id,
            'descendant_id' => $unit->id,
            'depth' => 0,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'recorded_at' => $recordedAt,
            'source_type' => 'TEST',
        ]];

        if ($parent instanceof OrgUnit) {
            $ancestors = OrgUnitClosure::query()
                ->where('descendant_id', $parent->id)
                ->where('valid_from', '<=', $validFrom)
                ->where('valid_to', '>', $validFrom)
                ->get();

            foreach ($ancestors as $ancestor) {
                $rows[] = [
                    'ancestor_id' => $ancestor->ancestor_id,
                    'descendant_id' => $unit->id,
                    'depth' => $ancestor->depth + 1,
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                    'recorded_at' => $recordedAt,
                    'source_type' => 'TEST',
                ];
            }
        }

        DB::table('org_unit_closure')->insert($rows);

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function person(array $overrides = []): Person
    {
        $idNumber = $overrides['id_number'] ?? null;
        $mobile = $overrides['mobile'] ?? '+8613800000000';
        unset($overrides['id_number'], $overrides['mobile']);

        $attributes = array_merge([
            'legal_name' => 'Synthetic Person',
            'preferred_name' => null,
            'id_type' => $idNumber !== null ? 'NATIONAL_ID' : null,
            'id_number_ciphertext' => $idNumber,
            'id_number_hash' => $idNumber !== null ? hash_hmac('sha256', $idNumber, (string) config('hrms.pii_hmac_key')) : null,
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'nationality' => 'CN',
            'mobile_ciphertext' => $mobile,
            'email' => 'synthetic@example.test',
        ], $overrides);

        return Person::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function employment(Person $person, string $employeeNo, array $overrides = []): Employment
    {
        $validFrom = (string) ($overrides['valid_from'] ?? '2026-01-01');
        $validTo = (string) ($overrides['valid_to'] ?? '9999-12-31');

        return Employment::query()->create(array_merge([
            'person_id' => $person->id,
            'employee_no' => $employeeNo,
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'hire_date' => $validFrom,
            'termination_date' => null,
            'seniority_start_date' => $validFrom,
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function assignment(Employment $employment, OrgUnit $orgUnit, array $overrides = []): Assignment
    {
        return Assignment::query()->create(array_merge([
            'employment_id' => $employment->id,
            'org_unit_id' => $orgUnit->id,
            'position_id' => null,
            'manager_assignment_id' => null,
            'assignment_type' => 'PRIMARY',
            'is_primary' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function position(?OrgUnit $orgUnit, string $code, array $overrides = []): Position
    {
        return Position::query()->create(array_merge([
            'org_unit_id' => $orgUnit?->id,
            'code' => $code,
            'title' => 'Synthetic Position',
            'grade' => 'P4',
            'headcount_limit' => 2,
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function terms(Employment $employment, int $version = 1, array $overrides = []): EmploymentTerms
    {
        return EmploymentTerms::query()->create(array_merge([
            'employment_id' => $employment->id,
            'version' => $version,
            'effective_from' => '2026-01-01',
            'effective_to' => '9999-12-31',
            'probation_end_date' => null,
            'work_location' => 'Shanghai',
            'work_schedule' => 'STANDARD',
            'salary_scheme_code' => 'SCHEME-A',
            'notice_period_days' => 30,
            'confidentiality' => true,
            'non_compete' => false,
            'signed_at' => '2026-01-01 00:00:00',
            'source_type' => 'TEST',
            'recorded_at' => $this->clock->now(),
        ], $overrides));
    }

    public function userLink(int $userId, Employment $employment, string $validFrom = '2026-01-01', string $validTo = '9999-12-31'): EmploymentUserLink
    {
        return EmploymentUserLink::query()->create([
            'user_id' => $userId,
            'employment_id' => $employment->id,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function document(Employment $employment, int $fileObjectId, array $overrides = []): Document
    {
        return Document::query()->create(array_merge([
            'owner_type' => 'EMPLOYMENT',
            'owner_id' => $employment->id,
            'category' => 'CONTRACT',
            'file_object_id' => $fileObjectId,
            'version' => 1,
            'signed_at' => '2026-01-01 00:00:00',
            'sha256' => str_repeat('a', 64),
            'expires_at' => null,
            'retention_class' => 'CONTRACT_10Y',
            'legal_hold' => false,
        ], $overrides));
    }

    public function fileObject(): int
    {
        return (int) DB::table('file_objects')->insertGetId([
            'storage_key' => 'private/contracts/'.bin2hex(random_bytes(8)).'.pdf',
            'sha256' => str_repeat('a', 64),
            'original_name' => 'contract.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'owner_user_id' => null,
            'purpose' => 'CONTRACT',
            'retention_class' => 'CONTRACT_10Y',
            'legal_hold' => false,
            'created_at' => $this->clock->now(),
        ]);
    }
}
