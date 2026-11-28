<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Models\StatutoryParameter;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\TaxParameterResolver;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\TimePayrollFactRow;
use App\Modules\Time\Models\TimePayrollFactVersion;
use App\Shared\Auth\AuthenticatedPrincipal;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePayrollRunMembershipDirectory;
use Tests\TestCase;

/**
 * Shared payroll fixture. Creates one salaried employment and a complete
 * synthetic statutory parameter version. All values are obviously DEMO data,
 * never real statutory rates.
 */
abstract class PayrollTestCase extends TestCase
{
    use RefreshDatabase;

    protected FrozenClock $clock;

    protected PayrollScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->aliasMiddleware('auth.session', TestSessionMiddleware::class);
        $this->app['router']->aliasMiddleware('capability', TestCapabilityMiddleware::class);

        config()->set('hrms.default_timezone', 'Asia/Shanghai');
        config()->set('hrms.pii_hmac_key', 'test-pii-hmac-key');

        $this->clock = new FrozenClock('2026-11-23T01:00:00Z');
        $this->app->instance(Clock::class, $this->clock);

        // Mutating HTTP requests pass the real CSRF middleware in production;
        // the test harness supplies a fixed header token.
        $this->withHeader('X-CSRF-Token', 'test-csrf-token');

        $this->app->instance(Authorizer::class, new AllowAllAuthorizer);

        $this->scenario = new PayrollScenario($this->clock);

        $this->actAs(10, null);
    }

    protected function actAs(int $userId, ?int $employmentId = null, bool $mfa = true): TestPrincipal
    {
        $principal = new TestPrincipal($userId, $employmentId, mfa: $mfa);
        $this->app->instance(Principal::class, $principal);
        $this->app->instance(AuthenticatedPrincipal::class, $principal);

        if ($this->app->bound(AuthenticationContext::class)) {
            $this->app->make(AuthenticationContext::class)->set($principal);
        }

        return $principal;
    }

    /**
     * @return array{scheme: PayrollScheme, employment_id: int}
     */
    /**
     * The IAM provider binds a fake membership directory under `testing`, so a
     * test that exercises run-scoped reads must declare its memberships. This
     * mirrors the production rule: membership is the four lifecycle actors.
     */
    protected function registerRunMembership(PayrollRun $run, int ...$userIds): void
    {
        /** @var FakePayrollRunMembershipDirectory $directory */
        $directory = $this->app->make(PayrollRunMembershipDirectory::class);

        $members = $userIds === [] ? [(int) $run->prepared_by] : $userIds;

        foreach ($members as $userId) {
            $directory->runsByUser[$userId] = array_values(array_unique([
                ...($directory->runsByUser[$userId] ?? []),
                (int) $run->id,
            ]));
            $directory->memberships[(int) $run->id] = array_values(array_unique([
                ...($directory->memberships[(int) $run->id] ?? []),
                'payroll.payslip.read',
                'payroll.run.read',
            ]));
        }

        // PayrollRunService is a singleton and may have captured the previous
        // directory instance when fixtures were built.
        $this->app->forgetInstance(PayrollRunService::class);
    }

    /** @return array<string, mixed> */
    protected function seedPayrollBase(int $baseSalaryCents = 1_000_000): array
    {
        $employment = $this->scenario->employment('E0001', $baseSalaryCents);
        $scheme = $this->scenario->scheme();

        if (StatutoryParameter::query()->exists()) {
            return ['scheme' => $scheme, 'employment_id' => (int) $employment->id];
        }

        app(TaxParameterResolver::class)->createVersion(
            [
                'parameter_set' => 'TAX',
                'version' => 'DEMO-TAX-2026',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'THRESHOLD_CENTS' => 500_000,
                'brackets' => [
                    ['lower_bound_cents' => 0, 'upper_bound_cents' => 1_000_000, 'rate_bps' => 3, 'quick_deduction_cents' => 0],
                    ['lower_bound_cents' => 1_000_000, 'upper_bound_cents' => 3_000_000, 'rate_bps' => 10, 'quick_deduction_cents' => 70_000],
                    ['lower_bound_cents' => 3_000_000, 'upper_bound_cents' => null, 'rate_bps' => 20, 'quick_deduction_cents' => 370_000],
                ],
            ],
            99,
            true,
            'DEMO-SYNTHETIC-NOT-LEGAL-ADVICE',
        );

        foreach (['SOCIAL_INSURANCE', 'HOUSING_FUND'] as $set) {
            app(TaxParameterResolver::class)->createVersion(
                [
                    'parameter_set' => $set,
                    'version' => 'DEMO-'.$set.'-2026',
                    'effective_from' => '2026-01-01',
                    'effective_to' => '2026-12-31',
                    'MIN_BASE_CENTS' => 400_000,
                    'MAX_BASE_CENTS' => 3_000_000,
                    'EMPLOYEE_RATE' => $set === 'SOCIAL_INSURANCE' ? '8.000000' : '7.000000',
                    'EMPLOYER_RATE' => $set === 'SOCIAL_INSURANCE' ? '16.000000' : '7.000000',
                ],
                99,
                true,
                'DEMO-SYNTHETIC-NOT-LEGAL-ADVICE',
            );
        }

        return ['scheme' => $scheme, 'employment_id' => (int) $employment->id];
    }

    /**
     * Test-only closed Time fact fixture. Production closes facts through the
     * Time service; tests provide deterministic aggregates through this helper.
     *
     * @param  list<array{business_date:string,scheduled_minutes?:int,worked_minutes?:int,paid_leave_minutes?:int,partial_leave_minutes?:int,unpaid_leave_minutes?:int,overtime_pay_minutes?:int,overtime_timeoff_minutes?:int,travel_minutes?:int}>  $facts
     */
    protected function closeTimeFact(int $employmentId, string $period, array $facts): TimePayrollFactVersion
    {
        $version = TimePayrollFactVersion::query()->create([
            'period' => $period,
            'version' => 1,
            'status' => TimePayrollFactVersion::STATUS_CLOSED,
            'source_hash' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR)),
            'exception_count' => 0,
            'precheck_json' => null,
            'generated_at' => $this->clock->now(),
            'closed_at' => $this->clock->now(),
            'closed_by' => 10,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        foreach ($facts as $fact) {
            TimePayrollFactRow::query()->create([
                'fact_version_id' => (int) $version->id,
                'employment_id' => $employmentId,
                'business_date' => (string) $fact['business_date'],
                'range_start' => (string) $fact['business_date'],
                'range_end' => (string) $fact['business_date'],
                'scheduled_minutes' => (int) ($fact['scheduled_minutes'] ?? 0),
                'worked_minutes' => (int) ($fact['worked_minutes'] ?? 0),
                'paid_leave_minutes' => (int) ($fact['paid_leave_minutes'] ?? 0),
                'partial_leave_minutes' => (int) ($fact['partial_leave_minutes'] ?? 0),
                'unpaid_leave_minutes' => (int) ($fact['unpaid_leave_minutes'] ?? 0),
                'overtime_pay_minutes' => (int) ($fact['overtime_pay_minutes'] ?? 0),
                'overtime_timeoff_minutes' => (int) ($fact['overtime_timeoff_minutes'] ?? 0),
                'travel_minutes' => (int) ($fact['travel_minutes'] ?? 0),
                'source_type' => 'ATTENDANCE',
                'source_id' => (string) $fact['business_date'],
                'source_revision' => 'TEST',
                'approval_status' => 'APPROVED',
                'source_hash' => hash('sha256', 'fact|'.$employmentId.'|'.json_encode($fact, JSON_THROW_ON_ERROR)),
                'recorded_at' => $this->clock->now(),
                'created_at' => $this->clock->now(),
            ]);
        }

        AttendancePeriodClosure::query()->create([
            'month' => $period,
            'status' => AttendancePeriodClosure::STATUS_CLOSED,
            'closed_by' => 10,
            'closed_at' => $this->clock->now(),
            'current_fact_version_id' => (int) $version->id,
        ]);

        return $version;
    }

    protected function payrollRun(int $schemeId, string $period = '2026-01'): PayrollRun
    {
        $start = $period.'-01';

        return PayrollRun::query()->create([
            'scheme_id' => $schemeId,
            'period' => $period,
            'earning_period_start' => $start,
            'earning_period_end' => date('Y-m-t', strtotime($start)),
            'posting_period' => $period,
            'pay_date' => date('Y-m-t', strtotime($start)),
            'cutoff_at' => $this->clock->now(),
            'status' => 'DRAFT',
            'run_type' => 'REGULAR',
            'prepared_by' => 10,
            'rule_version' => 'DEMO-V1',
            'snapshot_version' => 1,
        ]);
    }
}
