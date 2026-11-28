<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollSchemeItem;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRuleVersionService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Exceptions\ConflictException;
use Tests\Support\PayrollTestCase;

final class PayrollRuleVersionSnapshotTest extends PayrollTestCase
{
    public function test_only_published_rule_ast_is_frozen_and_executed_then_retirement_falls_back(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $scheme = $base['scheme'];
        $legacy = ['type' => 'const', 'value' => '50000'];
        $publishedAst = ['type' => 'const', 'value' => '70000'];

        $item = PayrollSchemeItem::query()->create([
            'scheme_id' => (int) $scheme->id,
            'payroll_item_id' => null,
            'item_code' => 'FORMULA_BONUS',
            'label' => 'Formula bonus',
            'category' => 'EARNING',
            'calculation' => 'FORMULA_LIMITED',
            'amount_cents' => 0,
            'rate_scaled' => null,
            'basis' => 'BASE_SALARY',
            'formula_json' => $legacy,
            'sort_order' => 10,
            'taxable' => true,
        ]);

        $rules = app(PayrollRuleVersionService::class);
        $draft = $rules->createDraft(10, (int) $item->id, $publishedAst);
        $runs = app(PayrollRunService::class);
        $this->closeTimeFact($employmentId, '2026-01', [['business_date' => '2026-01-01', 'scheduled_minutes' => 10_560, 'worked_minutes' => 10_560]]);
        $first = $runs->createRun(10, ['scheme_id' => (int) $scheme->id, 'period' => '2026-01']);
        $first = $runs->lockInputs((int) $first->id, 10);
        $firstSnapshot = PayrollInputSnapshot::query()->where('run_id', $first->id)->firstOrFail();
        $this->assertNull($firstSnapshot->payload_json['scheme']['items'][0]['rule_ast']);
        $this->assertSame(50_000, $this->formulaAmount($first));

        $published = $rules->publish(10, (int) $draft->id);
        $this->assertSame('PUBLISHED', (string) $published->status);
        $this->closeTimeFact($employmentId, '2026-02', [['business_date' => '2026-02-01', 'scheduled_minutes' => 9_600, 'worked_minutes' => 9_600]]);
        $second = $runs->createRun(10, ['scheme_id' => (int) $scheme->id, 'period' => '2026-02']);
        $second = $runs->lockInputs((int) $second->id, 10);
        $secondSnapshot = PayrollInputSnapshot::query()->where('run_id', $second->id)->firstOrFail();
        $this->assertSame(1, (int) $secondSnapshot->payload_json['scheme']['items'][0]['rule_version']);
        $this->assertSame($published->hash, $secondSnapshot->payload_json['scheme']['items'][0]['rule_hash']);
        $this->assertSame('CENT', $secondSnapshot->payload_json['scheme']['items'][0]['rule_unit']);
        $this->assertSame(70_000, $this->formulaAmount($second));

        $rules->retire(10, (int) $published->id, 'Superseded by synthetic test.');
        $this->assertSame('RETIRED', (string) PayrollRuleVersion::query()->findOrFail($published->id)->status);
    }

    public function test_publish_requires_published_dependencies_and_retire_blocks_dependents(): void
    {
        $base = $this->seedPayrollBase();
        $item = PayrollSchemeItem::query()->create([
            'scheme_id' => (int) $base['scheme']->id,
            'payroll_item_id' => null,
            'item_code' => 'DEP_RULE',
            'label' => 'Dependent rule',
            'category' => 'EARNING',
            'calculation' => 'FORMULA_LIMITED',
            'amount_cents' => 0,
            'rate_scaled' => null,
            'basis' => 'BASE_SALARY',
            'formula_json' => null,
            'sort_order' => 10,
            'taxable' => true,
        ]);
        $rules = app(PayrollRuleVersionService::class);
        $dependency = $rules->createDraft(10, (int) $item->id, ['type' => 'const', 'value' => '1']);
        $dependent = $rules->createDraft(10, (int) $item->id, ['type' => 'const', 'value' => '2'], dependencies: [(int) $dependency->id]);

        try {
            $rules->publish(10, (int) $dependent->id);
            $this->fail('A draft dependency must block publication.');
        } catch (ConflictException) {
            $this->assertSame('DRAFT', (string) $dependent->refresh()->status);
        }

        $rules->publish(10, (int) $dependency->id);
        $rules->publish(10, (int) $dependent->id);

        $this->expectException(ConflictException::class);
        $rules->retire(10, (int) $dependency->id, 'Trying to retire a referenced rule.');
    }

    public function test_unknown_rule_dependency_is_rejected(): void
    {
        $base = $this->seedPayrollBase();
        $item = PayrollSchemeItem::query()->create([
            'scheme_id' => (int) $base['scheme']->id,
            'payroll_item_id' => null,
            'item_code' => 'MISSING_DEP',
            'label' => 'Missing dependency rule',
            'category' => 'EARNING',
            'calculation' => 'FORMULA_LIMITED',
            'amount_cents' => 0,
            'rate_scaled' => null,
            'basis' => 'BASE_SALARY',
            'formula_json' => null,
            'sort_order' => 10,
            'taxable' => true,
        ]);

        $this->expectException(ConflictException::class);
        app(PayrollRuleVersionService::class)->createDraft(
            10,
            (int) $item->id,
            ['type' => 'const', 'value' => '1'],
            dependencies: [999999],
        );
    }

    public function test_rule_dependency_cycles_are_rejected_before_a_new_edge_is_added(): void
    {
        $base = $this->seedPayrollBase();
        $item = PayrollSchemeItem::query()->create([
            'scheme_id' => (int) $base['scheme']->id,
            'payroll_item_id' => null,
            'item_code' => 'CYCLE_RULE',
            'label' => 'Cycle rule',
            'category' => 'EARNING',
            'calculation' => 'FORMULA_LIMITED',
            'amount_cents' => 0,
            'rate_scaled' => null,
            'basis' => 'BASE_SALARY',
            'formula_json' => null,
            'sort_order' => 10,
            'taxable' => true,
        ]);
        $rules = app(PayrollRuleVersionService::class);
        $first = $rules->createDraft(10, (int) $item->id, ['type' => 'const', 'value' => '1']);
        $second = $rules->createDraft(10, (int) $item->id, ['type' => 'const', 'value' => '2'], dependencies: [(int) $first->id]);

        $corrupt = PayrollRuleVersion::query()->findOrFail($first->id);
        $corrupt->forceFill(['dependencies_json' => [(int) $second->id]])->save();

        $this->expectException(ConflictException::class);
        $rules->createDraft(10, (int) $item->id, ['type' => 'const', 'value' => '3'], dependencies: [(int) $first->id]);
    }

    private function formulaAmount(\App\Modules\Payroll\Models\PayrollRun $run): int
    {
        app(CalculationService::class)->calculate($run, 10);
        $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();

        return (int) PayslipLine::query()
            ->where('payslip_id', $payslip->id)
            ->where('item_code', 'FORMULA_BONUS')
            ->value('amount_cents');
    }
}
