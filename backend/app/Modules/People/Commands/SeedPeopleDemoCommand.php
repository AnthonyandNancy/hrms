<?php

declare(strict_types=1);

namespace App\Modules\People\Commands;

use App\Modules\IAM\Contracts\DemoUserDirectory;
use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\EmploymentUserLink;
use App\Modules\People\Models\Person;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SeedPeopleDemoCommand extends Command
{
    protected $signature = 'people:seed-demo';

    protected $description = 'Idempotently seed the DEMO tenant organization and synthetic people';

    public function handle(Clock $clock, DemoOrganizationSeeder $organizationSeeder, OrganizationFacts $organization): int
    {
        DB::transaction(function () use ($clock, $organizationSeeder, $organization): void {
            $seeded = $organizationSeeder->seedDemo();
            $entity = $organization->legalEntity(1, $seeded['legal_entity_id']);

            if ($entity === null) {
                throw new \RuntimeException('Demo legal entity was not seeded.');
            }

            $platform = $organization->orgUnit(1, $seeded['org_unit_ids']['platform']);
            $sales = $organization->orgUnit(1, $seeded['org_unit_ids']['sales']);

            if ($platform === null || $sales === null) {
                throw new \RuntimeException('Demo organization units were not seeded.');
            }

            $managerPerson = $this->person('DEMO-MANAGER', 'Demo Manager');
            $manager = $this->employment($managerPerson, $entity->id, 'DEMO-0001', $clock);
            $managerAssignment = $this->assignment($manager, $platform->id, null, 'PRIMARY', true, $clock);
            $this->terms($manager, $clock, baseSalaryMinor: 1_800_000);

            $employeePerson = $this->person('DEMO-EMPLOYEE', 'Demo Employee');
            $employee = $this->employment($employeePerson, $entity->id, 'DEMO-0002', $clock);
            $this->assignment($employee, $platform->id, (int) $managerAssignment->id, 'PRIMARY', true, $clock);
            $this->terms($employee, $clock, baseSalaryMinor: 900_000);

            $secondmentPerson = $this->person('DEMO-SECOND', 'Demo Secondment');
            $secondment = $this->employment($secondmentPerson, $entity->id, 'DEMO-0003', $clock);
            $this->assignment($secondment, $platform->id, (int) $managerAssignment->id, 'PRIMARY', true, $clock);
            $this->assignment($secondment, $sales->id, null, 'SECONDMENT', false, $clock, '2026-02-01', '2026-03-01');
            $this->terms($secondment, $clock, baseSalaryMinor: 1_100_000);

            $transferPerson = $this->person('DEMO-TRANSFER', 'Demo Transfer');
            $transferEmployment = $this->employment($transferPerson, $entity->id, 'DEMO-0004', $clock);
            $this->assignment($transferEmployment, $platform->id, null, 'PRIMARY', true, $clock, '2026-01-01', '2026-12-01');
            $this->assignment($transferEmployment, $sales->id, null, 'PRIMARY', true, $clock, '2026-12-01', '9999-12-31');
            $this->terms($transferEmployment, $clock, baseSalaryMinor: 1_200_000);

            // Link synthetic demo logins to synthetic employments so
            // self-service, manager scope and payslip self-read have a real
            // subject. Demo users are created by iam:seed-demo.
            $this->linkDemoUser($manager, 'demo.manager', $clock);
            $this->linkDemoUser($employee, 'demo.employee', $clock);
            $this->linkDemoUser($manager, 'demo.hr', $clock);
            $this->linkDemoUser($manager, 'demo.admin', $clock);

            $this->info('Demo people seeded with concurrency, secondment and future transfer.');
        });

        return self::SUCCESS;
    }

    private function linkDemoUser(Employment $employment, string $username, Clock $clock): void
    {
        $userId = (int) (app(DemoUserDirectory::class)
            ->userIdForUsername(1, $username) ?? 0);

        if ($userId === 0) {
            return;
        }

        EmploymentUserLink::query()->firstOrCreate(
            ['tenant_id' => 1, 'user_id' => $userId, 'employment_id' => (int) $employment->id, 'valid_from' => '2026-01-01'],
            [
                'valid_to' => '9999-12-31',
                'recorded_at' => $clock->now(),
                'source_type' => 'DEMO_SEED',
            ],
        );
    }

    private function person(string $key, string $name): Person
    {
        $idNumber = 'SYNTHETIC-'.$key;

        return Person::query()->firstOrCreate(
            ['tenant_id' => 1, 'legal_name' => $name],
            [
                'preferred_name' => null,
                'id_type' => 'DEMO',
                'id_number_ciphertext' => $idNumber,
                'id_number_hash' => hash_hmac('sha256', 'DEMO|'.$idNumber, (string) config('hrms.pii_hmac_key')),
                'birth_date' => '1990-01-01',
                'gender' => 'UNDISCLOSED',
                'nationality' => 'CN',
                'mobile_ciphertext' => '+8613800000000',
                'email' => strtolower($key).'@example.test',
            ],
        );
    }

    private function employment(Person $person, int $legalEntityId, string $employeeNo, Clock $clock): Employment
    {
        return Employment::query()->firstOrCreate(
            ['tenant_id' => 1, 'legal_entity_id' => $legalEntityId, 'employee_no' => $employeeNo],
            [
                'person_id' => (int) $person->id,
                'employment_type' => 'FULL_TIME',
                'status' => 'ACTIVE',
                'valid_from' => '2026-01-01',
                'valid_to' => '9999-12-31',
                'hire_date' => '2026-01-01',
                'termination_date' => null,
                'seniority_start_date' => '2026-01-01',
                'recorded_at' => $clock->now(),
                'source_type' => 'DEMO_SEED',
            ],
        );
    }

    private function assignment(
        Employment $employment,
        int $orgUnitId,
        ?int $managerAssignmentId,
        string $type,
        bool $primary,
        Clock $clock,
        string $from = '2026-01-01',
        string $to = '9999-12-31',
    ): Assignment {
        $existing = Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('org_unit_id', $orgUnitId)
            ->where('valid_from', $from)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Assignment::query()->create([
            'tenant_id' => 1,
            'employment_id' => (int) $employment->id,
            'org_unit_id' => $orgUnitId,
            'position_id' => null,
            'manager_assignment_id' => $managerAssignmentId,
            'assignment_type' => $type,
            'is_primary' => $primary,
            'valid_from' => $from,
            'valid_to' => $to,
            'recorded_at' => $clock->now(),
            'source_type' => 'DEMO_SEED',
        ]);
    }

    /**
     * Demo terms carry a synthetic salary so payroll can actually calculate.
     * The amount is obviously fake and exists only to exercise the pipeline.
     */
    private function terms(Employment $employment, Clock $clock, int $baseSalaryMinor = 1_000_000): void
    {
        EmploymentTerms::query()->firstOrCreate(
            ['employment_id' => (int) $employment->id, 'version' => 1],
            [
                'tenant_id' => 1,
                'effective_from' => '2026-01-01',
                'effective_to' => '9999-12-31',
                'probation_end_date' => null,
                'work_location' => 'Shanghai',
                'work_schedule' => 'STANDARD',
                'salary_scheme_code' => 'DEMO-SCHEME',
                'base_salary_minor' => $baseSalaryMinor,
                'salary_currency' => 'CNY',
                'notice_period_days' => 30,
                'confidentiality' => true,
                'non_compete' => false,
                'signed_at' => '2026-01-01 00:00:00',
                'recorded_at' => $clock->now(),
                'source_type' => 'DEMO_SEED',
            ],
        );
    }
}
