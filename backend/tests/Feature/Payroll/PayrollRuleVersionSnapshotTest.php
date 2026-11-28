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
        $draft = $rules->createDraft(10, (int) $item->id, $publishedAst, dependencies: ['base_salary_cents']);
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
        $this->assertSame(70_000, $this->formulaAmount($second));

        $rules->retire(10, (int) $published->id, 'Superseded by synthetic test.');
        $this->assertSame('RETIRED', (string) PayrollRuleVersion::query()->findOrFail($published->id)->status);
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
