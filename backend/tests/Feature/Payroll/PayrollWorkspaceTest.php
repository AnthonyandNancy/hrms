<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Http\Middleware\RequireCapability;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\Payroll\Authorization\EloquentPayrollRunMembershipDirectory;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\FakePayrollRunMembershipDirectory;
use Tests\Support\AllowAllAuthorizer;
use Tests\Support\PayrollTestCase;

/**
 * Volume 3 Task 12: the payroll workspace against the real lifecycle routes.
 *
 * The specialist regression deliberately re-registers the API table with the
 * production RequireCapability middleware and the real default-deny
 * CapabilityAuthorizer. The remaining lifecycle tests use the payroll harness
 * so they exercise payroll state, money, SOD and export behaviour rather than
 * repeating the authorization gate.
 */
final class PayrollWorkspaceTest extends PayrollTestCase
{
    public function test_real_lifecycle_actions_advance_a_draft_run_to_published(): void
    {
        $base = $this->seedPayrollBase();
        $run = app(PayrollRunService::class)->createRun(10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-01',
        ]);

        $this->actAs(10);
        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/lock-inputs')
            ->assertOk()
            ->assertJsonPath('data.status', 'INPUT_LOCKED');

        $calculated = $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/calculate')
            ->assertOk()
            ->assertJsonPath('data.run.status', 'CALCULATED');
        $this->assertIsInt($calculated->json('data.run.gross_cents'));

        $this->actAs(11);
        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/review')
            ->assertOk()
            ->assertJsonPath('data.status', 'REVIEWED');

        $this->actAs(12);
        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $this->actAs(13);
        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        // The index is membership-scoped. The production directory derives
        // membership from the recorded lifecycle actors, so mirror that for the
        // publisher before listing.
        $this->allowRunMembership();
        $list = $this->getJson('/v1/payroll/runs')->assertOk();
        /** @var array<string, mixed>|null $item */
        $item = null;

        foreach ((array) $list->json('data.items') as $candidate) {
            if (is_array($candidate) && (int) ($candidate['id'] ?? 0) === (int) $run->id) {
                $item = $candidate;
                break;
            }
        }

        $this->assertNotNull($item);
        $this->assertSame(1, $item['employee_count']);
        $this->assertIsInt($item['gross_cents']);
        $this->assertIsInt($item['deductions_cents']);
        $this->assertIsInt($item['net_cents']);
        $this->assertSame($item['gross_cents'] - $item['deductions_cents'], $item['net_cents']);
    }

    public function test_illegal_transition_is_a_409_with_a_stable_reason(): void
    {
        $base = $this->seedPayrollBase();
        $run = app(PayrollRunService::class)->createRun(10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-02',
        ]);

        $this->actAs(12);
        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/approve')
            ->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT')
            ->assertJsonPath('data.reason', 'RUN_NOT_APPROVABLE');

        $this->actAs(13);
        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/publish')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'INVALID_RUN_TRANSITION');
    }

    public function test_published_run_is_read_only_and_exports_remain_available(): void
    {
        $run = $this->publishedRun('2026-03');

        $this->actAs(14);
        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/calculate')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'RUN_NOT_CALCULABLE');

        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/lock-inputs')
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'RUN_NOT_LOCKABLE');

        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/cancel', [
            'reason' => 'Attempt to cancel a published run is illegal.',
        ])->assertStatus(409)->assertJsonPath('data.reason', 'RUN_SEALED');

        $this->getJson('/v1/payroll/runs/'.(int) $run->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        $bank = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/exports/bank')
            ->assertOk()
            ->assertJsonStructure(['data' => ['filename', 'content', 'sha256']]);
        $this->assertStringContainsString('payroll-bank-2026-03', (string) $bank->json('data.filename'));
        $this->assertStringContainsString('employee_id,period', (string) $bank->json('data.content'));

        $tax = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/exports/tax')
            ->assertOk()
            ->assertJsonStructure(['data' => ['filename', 'content', 'sha256']]);
        $this->assertStringContainsString('NOT_FOR_FILING', (string) $tax->json('data.content'));
    }

    public function test_employee_amounts_are_integer_minor_units_and_disappear_without_payslip_read(): void
    {
        $run = $this->calculatedRun('2026-04');

        /** @var AllowAllAuthorizer $authorizer */
        $authorizer = app(Authorizer::class);
        $authorizer->fieldProfiles['payroll.payslip.read'] = FieldProfile::P3_RESTRICTED;

        $visible = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/employees')->assertOk();
        $this->assertIsInt($visible->json('data.items.0.gross_cents'));
        $this->assertIsInt($visible->json('data.items.0.deductions_cents'));
        $this->assertIsInt($visible->json('data.items.0.net_cents'));

        $authorizer->fieldProfiles['payroll.payslip.read'] = FieldProfile::P2_SENSITIVE;
        $masked = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/employees')->assertOk();
        $this->assertArrayNotHasKey('gross_cents', $masked->json('data.items.0'));
        $this->assertArrayNotHasKey('net_cents', $masked->json('data.items.0'));
    }

    public function test_payroll_specialist_can_list_own_runs_and_cannot_read_another_specialists_run(): void
    {
        $base = $this->seedPayrollBase();
        $membership = new FakePayrollRunMembershipDirectory;

        $specialistA = User::factory()->create(['auth_version' => 1]);
        $specialistB = User::factory()->create(['auth_version' => 1]);
        $runs = app(PayrollRunService::class);
        $runA = $runs->createRun((int) $specialistA->id, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-05',
        ]);
        $runB = $runs->createRun((int) $specialistB->id, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-06',
        ]);

        $this->bindRunCapability($specialistA, (int) $runA->id, 'payroll.run.read', FieldProfile::P2_SENSITIVE);
        $this->bindRunCapability($specialistA, (int) $runA->id, 'payroll.payslip.read', FieldProfile::P3_RESTRICTED);
        $this->bindRunCapability($specialistB, (int) $runB->id, 'payroll.run.read', FieldProfile::P2_SENSITIVE);

        $membership->runsByUser[(int) $specialistA->id] = [(int) $runA->id];
        $membership->runsByUser[(int) $specialistB->id] = [(int) $runB->id];
        $membership->memberships[(int) $runA->id] = ['payroll.run.read', 'payroll.payslip.read'];
        $membership->memberships[(int) $runB->id] = ['payroll.run.read'];

        $this->useProductionAuthorization($membership);

        $this->actAs((int) $specialistA->id);
        $listA = $this->getJson('/v1/payroll/runs')->assertOk();
        $this->assertSame(1, $listA->json('data.total'));
        $this->assertSame([(int) $runA->id], array_column($listA->json('data.items'), 'id'));
        $this->getJson('/v1/payroll/runs/'.(int) $runA->id)->assertOk();
        $this->getJson('/v1/payroll/runs/'.(int) $runB->id)
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');

        $this->actAs((int) $specialistB->id);
        $listB = $this->getJson('/v1/payroll/runs')->assertOk();
        $this->assertSame(1, $listB->json('data.total'));
        $this->assertSame([(int) $runB->id], array_column($listB->json('data.items'), 'id'));
    }

    private function bindRunCapability(User $user, int $runId, string $capability, FieldProfile $profile): void
    {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::PAYROLL_RUN, $runId, $profile)
            ->create([
                'capability_code' => $capability,
                'purpose_code' => 'PAYROLL_SPECIALIST',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
    }

    /**
     * Install the production Eloquent membership adapter so the index proves
     * the real membership predicate (preparer/reviewer/approver/publisher), not
     * a test double. The harness normally binds the fake in the testing
     * environment.
     */
    private function allowRunMembership(): void
    {
        $this->app->instance(PayrollRunMembershipDirectory::class, new EloquentPayrollRunMembershipDirectory);
        $this->app->forgetInstance(PayrollRunService::class);
    }

    private function useProductionAuthorization(FakePayrollRunMembershipDirectory $membership): void
    {
        $this->app->instance(PayrollRunMembershipDirectory::class, $membership);
        // PayrollRunService is a singleton and was resolved while building the
        // fixtures, before the scoped membership directory was installed.
        // Rebuild it so its injected directory is the one we just configured.
        $this->app->forgetInstance(PayrollRunService::class);
        $this->app->forgetInstance(Authorizer::class);
        $this->app->forgetInstance(CapabilityAuthorizer::class);
        $this->app->instance(Authorizer::class, $this->app->make(CapabilityAuthorizer::class));

        $this->app['router']->aliasMiddleware('capability', RequireCapability::class);
        $this->app['router']->setRoutes(new RouteCollection);
        Route::prefix('v1')->middleware('api')->group(base_path('routes/api.php'));
    }

    private function calculatedRun(string $period): PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => $period]);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return $run->refresh();
    }

    private function publishedRun(string $period): PayrollRun
    {
        $run = $this->calculatedRun($period);
        $approval = app(ApprovalService::class);
        $approval->review((int) $run->id, 11);
        $approval->approve((int) $run->id, 12);

        return $approval->publish((int) $run->id, 13);
    }

    /**
     * V3-B1 regression: a member who holds P3 on run A but only P2 on run B must
     * not receive run B's amounts from the list or from the detail endpoint.
     */
    public function test_p3_on_one_run_does_not_reveal_another_runs_amounts(): void
    {
        $base = $this->seedPayrollBase();
        $membership = new FakePayrollRunMembershipDirectory;

        $actor = User::factory()->create(['auth_version' => 1]);
        $runs = app(PayrollRunService::class);
        $runA = $runs->createRun((int) $actor->id, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-05']);
        $runB = $runs->createRun((int) $actor->id, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-06']);

        // Both runs: read access. Only run A carries the P3 payslip grant.
        $this->bindRunCapability($actor, (int) $runA->id, 'payroll.run.read', FieldProfile::P2_SENSITIVE);
        $this->bindRunCapability($actor, (int) $runA->id, 'payroll.payslip.read', FieldProfile::P3_RESTRICTED);
        $this->bindRunCapability($actor, (int) $runB->id, 'payroll.run.read', FieldProfile::P2_SENSITIVE);

        $membership->runsByUser[(int) $actor->id] = [(int) $runA->id, (int) $runB->id];
        $membership->memberships[(int) $runA->id] = ['payroll.run.read', 'payroll.payslip.read'];
        $membership->memberships[(int) $runB->id] = ['payroll.run.read'];

        $this->useProductionAuthorization($membership);
        $this->actAs((int) $actor->id);

        /** @var array<int, array<string, mixed>> $items */
        $items = $this->getJson('/v1/payroll/runs')->assertOk()->json('data.items');
        $rows = [];
        foreach ($items as $item) {
            $rows[(int) $item['id']] = $item;
        }

        $this->assertArrayHasKey('gross_cents', $rows[(int) $runA->id], 'P3 run must expose amounts');
        $this->assertArrayNotHasKey('gross_cents', $rows[(int) $runB->id], 'P2-only run must omit amounts');
        $this->assertArrayNotHasKey('net_cents', $rows[(int) $runB->id], 'P2-only run must omit amounts');

        $detailB = $this->getJson('/v1/payroll/runs/'.(int) $runB->id)->assertOk();
        $this->assertArrayNotHasKey('gross_cents', $detailB->json('data'), 'P2-only run detail must omit amounts');
    }
}
