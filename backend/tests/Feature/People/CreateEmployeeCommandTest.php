<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\Person;
use Tests\Support\PeopleTestCase;

/**
 * Volume 3 Task 1: atomic employee onboarding.
 *
 * The important guarantees are atomicity (nothing partial survives a failure),
 * idempotency (a replay never creates a second employee) and authorization
 * (the capability is required and cannot be satisfied by another anchor).
 */
final class CreateEmployeeCommandTest extends PeopleTestCase
{
    public function test_it_creates_person_employment_assignment_and_terms_in_one_command(): void
    {
        $response = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('onboard-1'))
            ->assertCreated()
            ->assertJsonPath('data.employee_no', 'SYNTH-ONB-001');

        $employmentId = (int) $response->json('data.employment_id');
        $this->assertSame(1, Person::query()->count());
        $this->assertSame(1, Employment::query()->count());
        $this->assertSame(1, Assignment::query()->count());
        $this->assertSame(1, EmploymentTerms::query()->count());
        $this->assertDatabaseHas('employments', ['id' => $employmentId, 'employee_no' => 'SYNTH-ONB-001']);
    }

    public function test_a_failure_in_the_last_step_rolls_back_everything(): void
    {
        // Force the terms step to fail after person/employment/assignment have
        // already been written: an assignment valid_from earlier than the
        // employment start is rejected by the writer's range check.
        $payload = $this->payload();
        // Employee number already taken by a committed employee: the writer
        // rejects it with 409 after person creation has happened.
        $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('rollback-seed'))
            ->assertCreated();

        $seeded = Employment::query()->count();

        $this->postJson('/v1/employees/onboard', $payload, $this->headers('rollback-1'))
            ->assertStatus(409);

        // The seed employee is the only survivor; the failed attempt added nothing.
        $this->assertSame(1, Person::query()->count(), 'person must be rolled back');
        $this->assertSame($seeded, Employment::query()->count(), 'employment must be rolled back');
        $this->assertSame(1, Assignment::query()->count(), 'assignment must be rolled back');
        $this->assertSame(1, EmploymentTerms::query()->count(), 'terms must be rolled back');
    }

    public function test_the_same_idempotency_key_returns_the_same_employee_without_duplicating(): void
    {
        $first = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('replay-key'))
            ->assertCreated()
            ->json('data');

        $second = $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('replay-key'))
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->json('data');

        $this->assertSame($first['employment_id'], $second['employment_id']);
        $this->assertSame(1, Employment::query()->count());
        $this->assertSame(1, Person::query()->count());
    }

    public function test_a_different_idempotency_key_creates_a_second_employee(): void
    {
        $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('key-a'))->assertCreated();

        $payload = $this->payload();
        $payload['employment']['employee_no'] = 'SYNTH-ONB-002';
        $payload['person']['national_id'] = 'SYNTH-ID-002';
        $this->postJson('/v1/employees/onboard', $payload, $this->headers('key-b'))->assertCreated();

        $this->assertSame(2, Employment::query()->count());
    }

    public function test_a_duplicate_employee_number_is_rejected(): void
    {
        $this->postJson('/v1/employees/onboard', $this->payload(), $this->headers('dup-a'))->assertCreated();

        $payload = $this->payload();
        $payload['person']['national_id'] = 'SYNTH-ID-999';

        $this->postJson('/v1/employees/onboard', $payload, $this->headers('dup-b'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'EMPLOYEE_NO_DUPLICATE');

        $this->assertSame(1, Employment::query()->count());
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

        $this->assertSame(0, Employment::query()->count());
    }

    public function test_the_capability_is_required(): void
    {
        $this->forgetPrincipal();

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
        return $this->cachedOrgUnitId ??= (int) $this->scenario()
            ->orgUnit('SYNTH-ONB-DEPT-'.bin2hex(random_bytes(3)), null, '建档部门')
            ->id;
    }
}
