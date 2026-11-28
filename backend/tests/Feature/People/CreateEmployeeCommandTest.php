<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\Person;
use Tests\Support\LifecycleTestCase;

/**
 * The legacy People route is a compatibility facade over Lifecycle's canonical
 * onboarding command. It must return a pending Case and never write People.
 */
final class CreateEmployeeCommandTest extends LifecycleTestCase
{
    public function test_it_returns_a_pending_onboarding_case_and_no_domain_rows(): void
    {
        $response = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('onboard-1'))
            ->assertStatus(202)
            ->assertJsonPath('data.case_type', 'ONBOARDING')
            ->assertJsonPath('data.application_status', 'SUBMITTED')
            ->assertJsonPath('data.effect_status', 'NONE')
            ->assertJsonPath('data.canonical_endpoint', '/v1/lifecycle/onboarding')
            ->assertJsonPath('data.replayed', false)
            ->assertJsonStructure(['data' => ['case_id', 'case_no', 'case_type', 'application_status', 'effect_status', 'canonical_endpoint', 'replayed']]);

        $caseId = (int) $response->json('data.case_id');
        $this->assertGreaterThan(0, $caseId);
        $this->assertNotSame('', (string) $response->json('data.case_no'));
        $this->assertDatabaseHas('lifecycle_intents', ['case_id' => $caseId, 'case_type' => 'ONBOARDING']);
        $this->assertSame(1, LifecycleIntent::query()->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());
        $this->assertSame(0, Assignment::query()->count());
        $this->assertSame(0, EmploymentTerms::query()->count());
    }

    public function test_only_approving_the_case_creates_the_domain_rows(): void
    {
        $created = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('approval-path'))
            ->assertStatus(202)
            ->json('data');
        $caseId = (int) $created['case_id'];

        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());

        $this->approveCase($caseId, 'onboarding-approval-path')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $this->assertSame(1, Person::query()->count());
        $this->assertSame(1, Employment::query()->count());
        $this->assertSame(1, Assignment::query()->count());
        $this->assertSame(1, EmploymentTerms::query()->count());
        $this->assertDatabaseHas('employments', [
            'employee_no' => 'SYNTH-ONB-001',
            'source_type' => 'ONBOARDING',
            'source_id' => $caseId,
        ]);
        $this->assertDatabaseHas('employment_terms', [
            'base_salary_minor' => 1_000_000,
            'salary_currency' => 'CNY',
        ]);
    }

    public function test_the_same_idempotency_key_returns_the_same_pending_case(): void
    {
        $first = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('replay-key'))
            ->assertStatus(202)
            ->json('data');

        $second = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('replay-key'))
            ->assertStatus(202)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.replayed', true)
            ->json('data');

        $this->assertSame($first['case_id'], $second['case_id']);
        $this->assertSame($first['case_no'], $second['case_no']);
        $this->assertSame(1, LifecycleIntent::query()->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());
    }

    public function test_a_different_idempotency_key_creates_a_second_pending_case_without_domain_rows(): void
    {
        $first = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('key-a'))
            ->assertStatus(202)
            ->json('data.case_id');

        $payload = $this->payload();
        $payload['employment']['employee_no'] = 'SYNTH-ONB-002';
        $payload['person']['national_id'] = 'SYNTH-ID-002';
        $second = $this->postJson('/v1/employees/onboard', $payload, $this->headers('key-b'))
            ->assertStatus(202)
            ->json('data.case_id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, LifecycleIntent::query()->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());
        $this->assertSame(0, Assignment::query()->count());
        $this->assertSame(0, EmploymentTerms::query()->count());
    }

    public function test_a_second_key_for_the_same_pending_employee_number_returns_the_existing_case(): void
    {
        $first = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('dedup-a'))
            ->assertStatus(202)
            ->assertJsonPath('data.replayed', false)
            ->json('data');

        $second = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('dedup-b'))
            ->assertStatus(202)
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.case_id', $first['case_id'])
            ->json('data');

        $this->assertSame($first['case_no'], $second['case_no']);
        $this->assertSame(1, LifecycleIntent::query()->count());
        $this->assertSame(1, \App\Modules\People\Models\OnboardingDraft::query()->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());
    }

    public function test_both_onboarding_entry_points_share_the_same_idempotency_scope(): void
    {
        $org = $this->peopleScenario->orgUnit('SHARED-IDEMPOTENCY-ORG');
        $payload = [
            'person' => ['legal_name' => 'Synthetic Shared Entry'],
            'employee_no' => 'SHARED-IDEM-1',
            'org_unit_id' => (int) $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => ['work_location' => 'SHANGHAI', 'work_schedule' => 'STANDARD'],
        ];

        $canonical = $this->postJson('/v1/lifecycle/onboarding', $payload, ['Idempotency-Key' => 'shared-scope-key'])
            ->assertCreated()
            ->json('data.case_id');

        $compat = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('unused'))
            ->assertStatus(202);

        $this->assertNotSame($canonical, (int) $compat->json('data.case_id'));

        $replay = $this->postJson('/v1/lifecycle/onboarding', $payload, ['Idempotency-Key' => 'shared-scope-key'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->json('data.case_id');

        $this->assertSame($canonical, (int) $replay);
        $this->assertSame(2, LifecycleIntent::query()->count());
        $this->assertSame(0, Person::query()->count());
    }

    public function test_an_unknown_org_unit_never_creates_a_case_or_domain_row(): void
    {
        $payload = $this->payload();
        $payload['assignment']['org_unit_id'] = 999_999;

        $this->postJson('/v1/employees/onboard', $payload, $this->headers('unknown-org'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['data' => ['fields' => ['org_unit_id']]]);

        $this->assertSame(0, LifecycleIntent::query()->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());
    }

    public function test_a_missing_org_unit_never_creates_a_case_or_domain_row(): void
    {
        $payload = $this->payload();
        unset($payload['assignment']['org_unit_id']);

        $this->postJson('/v1/employees/onboard', $payload, $this->headers('missing-org'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['data' => ['fields' => ['assignment.org_unit_id']]]);

        $this->assertSame(0, LifecycleIntent::query()->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());
    }

    public function test_an_invalid_hire_date_never_creates_a_case_or_domain_row(): void
    {
        $payload = $this->payload();
        $payload['employment']['hire_date'] = '2026-13-01';

        $this->postJson('/v1/employees/onboard', $payload, $this->headers('invalid-date'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['data' => ['fields' => ['employment.hire_date']]]);

        $this->assertSame(0, LifecycleIntent::query()->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame(0, Employment::query()->count());
    }

    public function test_a_duplicate_employee_number_is_rejected_before_creating_another_case(): void
    {
        $person = $this->peopleScenario->person();
        $this->peopleScenario->employment($person, 'SYNTH-ONB-001');
        $existingCases = LifecycleIntent::query()->count();

        $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('dup-b'))
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'EMPLOYEE_NO_DUPLICATE');

        $this->assertSame($existingCases, LifecycleIntent::query()->count());
        $this->assertSame(1, Employment::query()->count());
        $this->assertSame(1, Person::query()->count());
    }

    public function test_an_invalid_payload_is_rejected_with_field_errors(): void
    {
        $payload = $this->payload();
        $payload['terms']['salary_minor'] = 0;
        $payload['employment']['employee_no'] = '';

        $this->postJson('/v1/employees/onboard', $payload, $this->headers('invalid-1'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['data' => ['fields' => ['employment.employee_no', 'terms.salary_minor']]]);

        $this->assertSame(0, LifecycleIntent::query()->count());
        $this->assertSame(0, Employment::query()->count());
    }

    public function test_the_capability_is_required(): void
    {
        $this->app->forgetInstance(\App\Modules\IAM\Contracts\Principal::class);
        $this->app->forgetInstance(\App\Shared\Auth\AuthenticatedPrincipal::class);

        $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('no-auth'))
            ->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'person' => [
                'given_name' => '合成',
                'family_name' => '员工',
                'national_id' => 'SYNTH-ID-001',
                'id_type' => 'CN_ID',
                'mobile' => '13800000000',
            ],
            'employment' => [
                'employee_no' => 'SYNTH-ONB-001',
                'hire_date' => '2026-12-01',
                'employment_type' => 'FULL_TIME',
            ],
            'assignment' => [
                'org_unit_id' => $this->orgUnitId(),
            ],
            'terms' => [
                'salary_minor' => 1_000_000,
                'currency' => 'CNY',
                'work_location' => '上海',
                'work_schedule' => 'STANDARD',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $key): array
    {
        return [
            'Idempotency-Key' => 'onboard-'.$key,
            'X-CSRF-Token' => 'test-csrf',
        ];
    }

    private ?int $cachedOrgUnitId = null;

    private function orgUnitId(): int
    {
        return $this->cachedOrgUnitId ??= (int) $this->peopleScenario
            ->orgUnit('SYNTH-ONB-DEPT-'.bin2hex(random_bytes(3)), null, '建档部门')
            ->id;
    }
}
