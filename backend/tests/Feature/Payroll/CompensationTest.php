<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\EmployeeCompensation;
use Tests\Fakes\SpySensitiveAccessAuditor;
use Tests\Support\PayrollTestCase;

final class CompensationTest extends PayrollTestCase
{
    private SpySensitiveAccessAuditor $sensitiveAuditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sensitiveAuditor = new SpySensitiveAccessAuditor;
        $this->app->instance(\App\Shared\Authorization\Contracts\SensitiveAccessAuditor::class, $this->sensitiveAuditor);
    }

    public function test_compensation_is_versioned_and_amounts_are_integer_cents(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];

        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-01-01',
            'base_salary_cents' => 1000000,
            'currency' => 'CNY',
        ], $this->headers('comp-1'))->assertCreated();

        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-07-01',
            'base_salary_cents' => 1200000,
            'currency' => 'CNY',
        ], $this->headers('comp-2'))->assertCreated();

        $rows = EmployeeCompensation::query()->where('employment_id', $employmentId)->orderBy('effective_from')->get();

        $this->assertCount(2, $rows, 'A new version must not overwrite the prior effective-dated row.');
        $this->assertSame('2026-01-01', (string) $rows[0]->effective_from);
        $this->assertSame(1000000, $rows[0]->base_salary_cents);
        $this->assertSame('2026-07-01', (string) $rows[1]->effective_from);
        $this->assertSame(1200000, $rows[1]->base_salary_cents);
        $this->assertIsInt($rows[0]->base_salary_cents);
    }

    public function test_the_same_employment_and_effective_date_conflicts_with_stable_reason(): void
    {
        $base = $this->seedPayrollBase();
        $payload = [
            'employment_id' => (int) $base['employment_id'],
            'effective_from' => '2026-01-01',
            'base_salary_cents' => 1000000,
            'currency' => 'CNY',
        ];

        $this->postJson('/v1/payroll/employee-plans', $payload, $this->headers('dup-1'))->assertCreated();
        $conflict = $this->postJson('/v1/payroll/employee-plans', $payload, $this->headers('dup-2'))->assertConflict();

        $this->assertSame('COMPENSATION_EFFECTIVE_DATE_EXISTS', $conflict->json('data.reason'));
        $this->assertSame(1, EmployeeCompensation::query()->count());
    }

    public function test_a_write_without_an_idempotency_key_is_422(): void
    {
        $base = $this->seedPayrollBase();

        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => (int) $base['employment_id'],
            'effective_from' => '2026-02-01',
            'base_salary_cents' => 1100000,
        ], ['X-CSRF-Token' => 'test-csrf-token'])->assertUnprocessable();
        $this->assertSame(0, EmployeeCompensation::query()->count());
    }

    public function test_a_replay_returns_200_and_does_not_duplicate_the_version(): void
    {
        $base = $this->seedPayrollBase();
        $payload = [
            'employment_id' => (int) $base['employment_id'],
            'effective_from' => '2026-03-01',
            'base_salary_cents' => 900000,
            'currency' => 'CNY',
        ];

        $this->postJson('/v1/payroll/employee-plans', $payload, $this->headers('replay-1'))->assertCreated();
        $replay = $this->postJson('/v1/payroll/employee-plans', $payload, $this->headers('replay-1'))->assertOk();

        $replay->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, EmployeeCompensation::query()->count());
    }

    public function test_a_same_key_with_a_different_payload_is_409(): void
    {
        $base = $this->seedPayrollBase();
        $payload = [
            'employment_id' => (int) $base['employment_id'],
            'effective_from' => '2026-04-01',
            'base_salary_cents' => 900000,
            'currency' => 'CNY',
        ];

        $this->postJson('/v1/payroll/employee-plans', $payload, $this->headers('mismatch-1'))->assertCreated();
        $conflict = $this->postJson('/v1/payroll/employee-plans', [
            ...$payload,
            'base_salary_cents' => 950000,
        ], $this->headers('mismatch-1'))->assertConflict();

        $this->assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $conflict->json('data.reason'));
    }

    public function test_a_p2_reader_gets_compensation_metadata_without_the_p3_amount(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-05-01',
            'base_salary_cents' => 1000000,
        ], $this->headers('read-p2-1'))->assertCreated();

        /** @var \Tests\Support\AllowAllAuthorizer $authorizer */
        $authorizer = app(\App\Shared\Authorization\Authorizer::class);
        $authorizer->fieldProfiles['payroll.compensation.read'] = \App\Shared\Authorization\FieldProfile::P2_SENSITIVE;

        $response = $this->getJson('/v1/payroll/employee-plans', $this->headers())->assertOk();

        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($employmentId, $response->json('data.items.0.employment_id'));
        $this->assertArrayNotHasKey('base_salary_cents', $response->json('data.items.0'));
        $this->assertFalse($response->json('data.partial'));
    }

    public function test_a_p3_reader_gets_the_amount_and_a_read_audit_record(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-06-01',
            'base_salary_cents' => 1234567,
        ], $this->headers('read-p3-1'))->assertCreated();

        /** @var \Tests\Support\AllowAllAuthorizer $authorizer */
        $authorizer = app(\App\Shared\Authorization\Authorizer::class);
        $authorizer->fieldProfiles['payroll.compensation.read'] = \App\Shared\Authorization\FieldProfile::P3_RESTRICTED;

        $response = $this->getJson('/v1/payroll/employee-plans', $this->headers())->assertOk();

        $this->assertSame(1234567, $response->json('data.items.0.base_salary_cents'));

        $reads = array_values(array_filter(
            $this->sensitiveAuditor->records,
            static fn (array $record): bool => ($record['context']['access'] ?? null) === 'READ',
        ));

        $this->assertNotEmpty($reads, 'A P3 field read must leave sensitive access evidence.');
        $this->assertSame('payroll.compensation.read', $reads[0]['capability']);
        $this->assertContains('base_salary_cents', $reads[0]['fields']);
        $this->assertStringNotContainsString('1234567', json_encode($reads, JSON_THROW_ON_ERROR));
    }

    public function test_a_compensation_write_leaves_p3_write_audit_without_the_value(): void
    {
        $base = $this->seedPayrollBase();

        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => (int) $base['employment_id'],
            'effective_from' => '2026-08-01',
            'base_salary_cents' => 7654321,
        ], $this->headers('write-audit-1'))->assertCreated();

        $writes = array_values(array_filter(
            $this->sensitiveAuditor->records,
            static fn (array $record): bool => ($record['context']['access'] ?? null) === 'WRITE',
        ));

        $this->assertNotEmpty($writes, 'A P3 write must leave sensitive access evidence.');
        $this->assertSame('payroll.compensation.manage', $writes[0]['capability']);
        $this->assertContains('base_salary_cents', $writes[0]['fields']);
        $this->assertStringNotContainsString('7654321', json_encode($writes, JSON_THROW_ON_ERROR));
    }

    public function test_salary_adjustment_requires_an_approved_case(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $caseId = $this->createCase('IN_REVIEW');

        $conflict = $this->postJson('/v1/payroll/adjustments', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-09-01',
            'base_salary_cents' => 1300000,
            'currency' => 'CNY',
            'reason' => 'Synthetic approved salary change',
            'case_id' => $caseId,
        ], $this->headers('adjust-unapproved-1'))->assertConflict();

        $this->assertSame('SALARY_ADJUSTMENT_CASE_NOT_APPROVED', $conflict->json('data.reason'));
        $this->assertDatabaseCount('salary_adjustments', 0);

        $this->postJson('/v1/payroll/adjustments', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-09-01',
            'base_salary_cents' => 1300000,
            'currency' => 'CNY',
            'reason' => 'Synthetic approved salary change',
            'case_id' => $this->createCase('DRAFT'),
        ], $this->headers('adjust-draft-1'))->assertConflict();
        $this->assertDatabaseCount('salary_adjustments', 0);
    }

    public function test_an_approved_case_creates_a_version_and_an_adjustment_row(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $caseId = $this->createCase('APPROVED', [
            'employment_id' => $employmentId,
            'effective_date' => '2026-10-01',
            'base_salary_cents' => 1400000,
        ]);

        $response = $this->postJson('/v1/payroll/adjustments', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-10-01',
            'base_salary_cents' => 1400000,
            'currency' => 'CNY',
            'reason' => 'Synthetic approved salary change',
            'case_id' => $caseId,
        ], $this->headers('adjust-approved-1'))->assertCreated();

        $this->assertSame($caseId, $response->json('data.case_id'));
        $this->assertDatabaseHas('salary_adjustments', ['case_id' => $caseId, 'base_salary_cents' => 1400000]);
        $this->assertDatabaseHas('employee_compensations', ['employment_id' => $employmentId, 'base_salary_cents' => 1400000]);
    }

    public function test_an_approved_case_can_only_be_applied_once(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $caseId = $this->createCase('APPROVED', [
            'employment_id' => $employmentId,
            'effective_date' => '2026-11-01',
            'base_salary_cents' => 1500000,
        ]);
        $payload = [
            'employment_id' => $employmentId,
            'effective_from' => '2026-11-01',
            'base_salary_cents' => 1500000,
            'currency' => 'CNY',
            'reason' => 'Synthetic approved salary change',
            'case_id' => $caseId,
        ];

        $this->postJson('/v1/payroll/adjustments', $payload, $this->headers('adjust-once-1'))->assertCreated();
        $conflict = $this->postJson('/v1/payroll/adjustments', $payload, $this->headers('adjust-once-2'))->assertConflict();

        $this->assertSame('SALARY_ADJUSTMENT_CASE_ALREADY_APPLIED', $conflict->json('data.reason'));
        $this->assertSame(1, \App\Modules\Payroll\Models\SalaryAdjustment::query()->count());
    }

    public function test_adjustment_amount_must_match_the_approved_case(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $caseId = $this->createCase('APPROVED', [
            'employment_id' => $employmentId,
            'effective_date' => '2026-12-01',
            'base_salary_cents' => 1500000,
        ]);

        $conflict = $this->postJson('/v1/payroll/adjustments', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-12-01',
            'base_salary_cents' => 1600000,
            'currency' => 'CNY',
            'reason' => 'Synthetic approved salary change',
            'case_id' => $caseId,
        ], $this->headers('adjust-amount-1'))->assertConflict();

        $this->assertSame('SALARY_ADJUSTMENT_AMOUNT_MISMATCH', $conflict->json('data.reason'));
        $this->assertSame(0, EmployeeCompensation::query()->count());
    }

    public function test_adjustments_list_returns_the_real_projection_envelope(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $caseId = $this->createCase('APPROVED', [
            'employment_id' => $employmentId,
            'effective_date' => '2026-12-15',
            'base_salary_cents' => 1750000,
        ]);
        $this->postJson('/v1/payroll/adjustments', [
            'employment_id' => $employmentId,
            'effective_from' => '2026-12-15',
            'base_salary_cents' => 1750000,
            'currency' => 'CNY',
            'reason' => 'Synthetic approved salary change',
            'case_id' => $caseId,
        ], $this->headers('adjust-list-1'))->assertCreated();

        /** @var \Tests\Support\AllowAllAuthorizer $authorizer */
        $authorizer = app(\App\Shared\Authorization\Authorizer::class);
        $authorizer->fieldProfiles['payroll.compensation.read'] = \App\Shared\Authorization\FieldProfile::P3_RESTRICTED;

        $response = $this->getJson('/v1/payroll/adjustments', $this->headers())->assertOk();

        $this->assertSame('LIVE', $response->json('data.freshness'));
        $this->assertFalse($response->json('data.partial'));
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($caseId, $response->json('data.items.0.case_id'));
        $this->assertIsInt($response->json('data.items.0.base_salary_cents'));
    }

    public function test_a_denied_read_capability_is_403(): void
    {
        $this->seedPayrollBase();

        /** @var \Tests\Support\AllowAllAuthorizer $authorizer */
        $authorizer = app(\App\Shared\Authorization\Authorizer::class);
        $authorizer->deniedCapabilities = ['payroll.compensation.read'];

        $this->getJson('/v1/payroll/employee-plans', $this->headers())->assertForbidden();
        $this->getJson('/v1/payroll/adjustments', $this->headers())->assertForbidden();
    }

    public function test_an_unauthenticated_read_is_401(): void
    {
        $this->seedPayrollBase();
        $this->app->forgetInstance(\App\Modules\IAM\Contracts\Principal::class);

        $this->getJson('/v1/payroll/employee-plans', $this->headers())->assertUnauthorized();
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $idempotencyKey = ''): array
    {
        $headers = [
            'X-Legal-Entity-Id' => (string) $this->legalEntityId,
            'X-CSRF-Token' => 'test-csrf-token',
        ];

        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createCase(string $status, array $payload = []): int
    {
        $case = \App\Modules\Workflow\Models\CaseModel::query()->create([
            'tenant_id' => $this->tenantId,
            'case_no' => 'SYN-'.bin2hex(random_bytes(4)),
            'type' => 'TERMS_CHANGE',
            'subject_type' => 'employment',
            'subject_id' => (int) ($payload['employment_id'] ?? 1),
            'title' => 'Synthetic salary change',
            'payload_json' => $payload,
            'status' => $status,
            'effect_status' => 'NONE',
            'requester_user_id' => 10,
            'template_id' => null,
            'template_version' => 1,
            'template_snapshot_json' => ['synthetic' => true],
            'business_date' => '2026-11-23',
            'submitted_at' => $status === 'DRAFT' ? null : '2026-11-23 01:00:00',
            'closed_at' => in_array($status, ['APPROVED', 'REJECTED'], true) ? '2026-11-23 02:00:00' : null,
        ]);

        return (int) $case->id;
    }
}
