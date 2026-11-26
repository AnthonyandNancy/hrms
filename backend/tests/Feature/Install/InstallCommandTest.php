<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Modules\IAM\Models\Capability;
use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\User;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\People\Models\Employment;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\LegalEntityCalendar;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-11-24T09:00:00Z'));
    }

    public function test_it_creates_the_reference_baseline_and_exactly_one_admin(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('hrms:install', $this->installOptions());
        if ($exit !== 0) {
            fwrite(STDERR, 'INSTALL OUTPUT:
'.\Illuminate\Support\Facades\Artisan::output().'
');
        }
        $this->assertSame(0, $exit);

        $this->assertSame(1, User::query()->count(), 'The installer must create exactly one account.');
        $this->assertDatabaseHas('users', ['username' => 'installer', 'is_privileged' => true]);
        // MFA is disabled by default (owner decision, ADR-0018): the
        // installer must not fabricate an enrolled timestamp.
        $this->assertSame(0, MfaFactor::query()->whereNotNull('confirmed_at')->count());
        $this->assertNull(User::query()->firstOrFail()->mfa_enrolled_at);

        $this->assertSame(14, Role::query()->where('is_template', true)->count());
        // Derive from the code-owned catalog so adding a capability never
        // silently leaves a stale installer assertion behind.
        $this->assertSame(
            count(app(\App\Modules\IAM\Authorization\CapabilityCatalog::class)->codes()),
            Capability::query()->count(),
        );
        $this->assertGreaterThanOrEqual(9, WorkflowTemplate::query()->count());

        foreach (['leave.request', 'attendance.correction', 'travel.request', 'transfer.request', 'contract.renewal', 'onboarding.request', 'offboarding.request', 'payroll.approval', 'generic.approval'] as $code) {
            $this->assertDatabaseHas('workflow_templates', ['tenant_id' => 1, 'code' => $code]);
        }
        $this->assertSame(3, LeaveType::query()->count());
        $this->assertSame(1, LegalEntityCalendar::query()->count());
        $this->assertSame(1, ShiftTemplate::query()->count());

        $this->assertSame(0, Employment::query()->count(), 'The installer must create zero employees.');
        $this->assertSame(0, PayrollRun::query()->count());
        $this->assertSame(0, CaseModel::query()->count());
    }

    public function test_it_is_idempotent(): void
    {
        $options = $this->installOptions();
        $this->artisan('hrms:install', $options)->assertExitCode(0);

        $before = [
            'users' => User::query()->count(),
            'roles' => Role::query()->count(),
            'leave_types' => LeaveType::query()->count(),
            'calendars' => LegalEntityCalendar::query()->count(),
            'shifts' => ShiftTemplate::query()->count(),
            'mfa' => MfaFactor::query()->count(),
        ];

        $this->artisan('hrms:install', $options)->assertExitCode(0);

        $this->assertSame($before['users'], User::query()->count());
        $this->assertSame($before['roles'], Role::query()->count());
        $this->assertSame($before['leave_types'], LeaveType::query()->count());
        $this->assertSame($before['calendars'], LegalEntityCalendar::query()->count());
        $this->assertSame($before['shifts'], ShiftTemplate::query()->count());
        $this->assertSame($before['mfa'], MfaFactor::query()->count());
    }

    public function test_it_fails_loudly_when_the_password_is_too_short(): void
    {
        $this->artisan('hrms:install', [...$this->installOptions(), '--admin-password' => 'short'])->assertExitCode(1);

        $this->assertSame(0, User::query()->count(), 'A failed install must not create a partial admin.');
    }

    /**
     * @return array<string, string>
     */
    private function installOptions(): array
    {
        return [
            '--company-code' => 'ACME-CN',
            '--company-name' => 'Acme China',
            '--country' => 'CN',
            '--timezone' => 'Asia/Shanghai',
            '--currency' => 'CNY',
            '--admin-username' => 'installer',
            '--admin-email' => 'installer@example.test',
            '--admin-password' => 'Install!2026Synthetic',
            '--annual-days' => '10.00',
            '--legal-basis' => 'Company policy appendix A',
        ];
    }

    public function test_it_requires_annual_days_and_legal_basis(): void
    {
        $options = $this->installOptions();
        unset($options['--annual-days'], $options['--legal-basis']);

        $this->artisan('hrms:install', $options)->assertExitCode(1);
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_failed_step_prints_the_partial_report(): void
    {
        $options = $this->installOptions();
        // Force the capability step to fail by pre-creating an unknown binding
        // that sync refuses to remove.
        \App\Modules\IAM\Models\AuthorityBinding::factory()->create([
            'capability_code' => 'definitely.unknown.code',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
        ]);

        $this->artisan('hrms:install', $options)
            ->expectsOutputToContain('capabilities')
            ->assertExitCode(1);
    }
}
