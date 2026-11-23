<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Services\TaxParameterResolver;
use App\Shared\Authorization\Authorizer;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared payroll fixture. Creates a legal entity, one salaried employment and
 * a complete synthetic statutory parameter version. All values are obviously
 * DEMO data, never real statutory rates.
 */
abstract class PayrollTestCase extends TestCase
{
    use RefreshDatabase;

    protected FrozenClock $clock;

    protected PayrollScenario $scenario;

    protected int $tenantId = 1;

    protected int $legalEntityId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->aliasMiddleware('auth.session', TestSessionMiddleware::class);
        $this->app['router']->aliasMiddleware('capability', TestCapabilityMiddleware::class);

        config()->set('hrms.default_timezone', 'Asia/Shanghai');
        config()->set('hrms.pii_hmac_key', 'test-pii-hmac-key');

        $this->clock = new FrozenClock('2026-11-23T01:00:00Z');
        $this->app->instance(Clock::class, $this->clock);

        $this->app->instance(Authorizer::class, new AllowAllAuthorizer);

        $this->scenario = new PayrollScenario($this->tenantId, $this->clock);
        $this->legalEntityId = (int) $this->scenario->legalEntity()->id;

        $this->actAs(10, null);
    }

    protected function actAs(int $userId, ?int $employmentId = null, bool $mfa = true): TestPrincipal
    {
        $principal = new TestPrincipal($this->tenantId, $userId, $employmentId, mfa: $mfa);
        $this->app->instance(Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);

        if ($this->app->bound(\App\Modules\IAM\Auth\AuthenticationContext::class)) {
            $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);
        }

        return $principal;
    }

    /**
     * @return array{scheme: PayrollScheme, employment_id: int}
     */
    protected function seedPayrollBase(int $baseSalaryCents = 1_000_000): array
    {
        $employment = $this->scenario->employment('E0001', $baseSalaryCents);
        $scheme = $this->scenario->scheme();

        if (\App\Modules\Payroll\Models\StatutoryParameter::query()->where('tenant_id', $this->tenantId)->exists()) {
            return ['scheme' => $scheme, 'employment_id' => (int) $employment->id];
        }

        app(TaxParameterResolver::class)->createVersion(
            $this->tenantId,
            $this->legalEntityId,
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
                $this->tenantId,
                $this->legalEntityId,
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

    protected function payrollRun(int $schemeId, string $period = '2026-01'): \App\Modules\Payroll\Models\PayrollRun
    {
        $start = $period.'-01';

        return \App\Modules\Payroll\Models\PayrollRun::query()->create([
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->legalEntityId,
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
