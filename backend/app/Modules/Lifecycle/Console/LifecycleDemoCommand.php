<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Console;

use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Services\LifecycleService;
use App\Modules\Organization\Contracts\DemoOrganizationSeeder;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Shared\Time\Clock;
use App\Shared\Time\TenantBusinessDate;
use Illuminate\Console\Command;

final class LifecycleDemoCommand extends Command
{
    protected $signature = 'lifecycle:demo {--scenario=onboarding : onboarding|transfer|offboarding|rehire} {--tenant=1 : Tenant id} {--user=1 : Requester user id}';

    protected $description = 'Create a synthetic lifecycle Case for a demo scenario (no approval is auto-recorded).';

    public function handle(
        LifecycleService $lifecycle,
        DemoOrganizationSeeder $organization,
        PeopleEmploymentWriter $people,
        TenantBusinessDate $businessDates,
        Clock $clock,
    ): int {
        $scenario = (string) $this->option('scenario');

        if (! in_array($scenario, ['onboarding', 'transfer', 'offboarding', 'rehire'], true)) {
            $this->error('Scenario must be onboarding, transfer, offboarding or rehire.');

            return self::FAILURE;
        }

        $tenantId = max(1, (int) $this->option('tenant'));
        $userId = max(1, (int) $this->option('user'));
        $seed = $organization->seedDemo();
        $orgId = (int) ($seed['org_unit_ids']['engineering'] ?? $seed['org_unit_ids']['root']);
        $businessDate = $businessDates->today($tenantId)->value();

        $payload = match ($scenario) {
            'onboarding', 'rehire' => [
                'person' => ['legal_name' => 'Demo Lifecycle Person'],
                'legal_entity_id' => (int) $seed['legal_entity_id'],
                'employee_no' => 'DEMO-LIFE-'.strtoupper($scenario).'-'.substr((string) $clock->now()->timestamp, -5),
                'org_unit_id' => $orgId,
                'hire_date' => $businessDate,
                'employment_type' => 'FULL_TIME',
                'terms' => ['work_location' => 'Shanghai', 'work_schedule' => 'STANDARD', 'base_salary_minor' => 1_500_000, 'salary_currency' => 'CNY', 'notice_period_days' => 30],
                'submit' => true,
            ],
            default => [
                'employment_id' => (int) $people->createEmployment($tenantId, [
                    'person_id' => $people->createPerson($tenantId, ['legal_name' => 'Demo Scenario Person'], $userId),
                    'legal_entity_id' => (int) $seed['legal_entity_id'],
                    'employee_no' => 'DEMO-LIFE-'.strtoupper($scenario).'-'.$clock->now()->format('His'),
                    'employment_type' => 'FULL_TIME',
                    'status' => 'ACTIVE',
                    'valid_from' => $businessDate,
                    'hire_date' => $businessDate,
                    'source_type' => 'DEMO_SEED',
                ], $userId),
                'org_unit_id' => $orgId,
                'effective_date' => $businessDate,
                'reason' => 'Synthetic demo scenario',
                'submit' => true,
            ],
        };

        if ($scenario === 'offboarding') {
            $payload['last_working_date'] = $businessDate;
            $payload['termination_type'] = 'RESIGNATION';
            $payload['reason_code'] = 'DEMO';
        }

        $type = match ($scenario) {
            'onboarding' => LifecycleCaseType::ONBOARDING,
            'transfer' => LifecycleCaseType::ASSIGNMENT_CHANGE,
            'offboarding' => LifecycleCaseType::OFFBOARD,
            'rehire' => LifecycleCaseType::REHIRE,
        };

        $created = $lifecycle->createCase($tenantId, $userId, $type, $payload, $businessDate, true);

        $this->info('Created '.$scenario.' case '.$created['case']->caseNo.' (id '.$created['case']->id.'); approve it through /v1/cases/{id}/decisions.');

        return self::SUCCESS;
    }
}
