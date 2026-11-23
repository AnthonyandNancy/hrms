<?php

declare(strict_types=1);

namespace App\Modules\Platform\Demo\Services;

use App\Modules\Platform\Demo\DemoDataset;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunStatus;
use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Models\StagingRow;
use App\Modules\Platform\Migration\Services\MigrationReconciler;

/**
 * Asserts the demo invariants and produces a human walkthrough checklist. A
 * shadow-mode demo is explicitly described as shadow: this verifier must never
 * turn missing business modules into a green "live system" claim.
 */
final class DemoVerifier
{
    public function __construct(private readonly MigrationReconciler $reconciler) {}

    /**
     * @return array{passed: bool, mode: string, checks: list<array{name: string, passed: bool, detail: string}>, walkthrough: list<string>, deferred: array<string, string>}
     */
    public function verify(int $tenantId = DemoSeeder::TENANT_ID): array
    {
        $checks = [];
        $dataset = DemoDataset::query()
            ->where('tenant_id', $tenantId)
            ->where('dataset', DemoSeeder::DATASET)
            ->first();

        $checks[] = $this->check('demo dataset exists', $dataset !== null, $dataset === null ? 'Run demo:seed first.' : 'Dataset registered.');

        if ($dataset === null) {
            return ['passed' => false, 'mode' => 'MISSING', 'checks' => $checks, 'walkthrough' => [], 'deferred' => []];
        }

        $checks[] = $this->check(
            'mode is declared honestly',
            in_array($dataset->mode, ['SHADOW', 'LIVE'], true),
            "Dataset mode is {$dataset->mode}.",
        );

        foreach (DemoSeeder::BUSINESS_DOMAINS as $domainValue) {
            $domain = MigrationDomain::from($domainValue);
            $latest = MigrationRun::query()
                ->where('tenant_id', $tenantId)
                ->where('domain', $domainValue)
                ->where('status', MigrationRunStatus::APPLIED->value)
                ->latest('id')
                ->first();

            $staged = $latest === null ? 0 : StagingRow::forDomain($domain)->newQuery()->where('run_id', $latest->id)->count();
            $checks[] = $this->check(
                "{$domainValue} applied",
                $latest !== null && $staged > 0,
                $latest === null ? 'No applied run.' : "run {$latest->id}, staged rows {$staged}.",
            );

            if ($latest !== null) {
                $report = $this->reconciler->reconcile($latest);
                $checks[] = $this->check(
                    "{$domainValue} reconciles",
                    $report->passed,
                    $report->passed ? 'Counts and sums match.' : 'Differences: '.json_encode($report->differences),
                );
            }
        }

        $payrollCount = MigrationRun::query()
            ->where('tenant_id', $tenantId)
            ->where('domain', MigrationDomain::PAYROLL_YTD->value)
            ->where('status', MigrationRunStatus::APPLIED->value)
            ->count();
        $checks[] = $this->check('payroll YTD opening present', $payrollCount > 0, "{$payrollCount} payroll YTD run(s).");

        $populated = $dataset->populated_json;
        $allDomainsReported = count($populated) === count(DemoSeeder::BUSINESS_DOMAINS);
        $checks[] = $this->check('all six domains reported', $allDomainsReported, count($populated).' domain(s) in the dataset manifest.');

        return [
            'passed' => ! in_array(false, array_column($checks, 'passed'), true),
            'mode' => $dataset->mode,
            'checks' => $checks,
            'walkthrough' => [
                'Sign in with a demo operator account (credentials are documented in README; none are production accounts).',
                'Open the employee directory and confirm 12 synthetic employees and 8 organization units.',
                'Open one employment timeline and verify hire date, primary assignment and manager chain.',
                'Open leave balances and trace each opening balance to its migration staging row and GRANT ledger entry.',
                'Open payroll YTD for one employee and compare gross/net/tax totals with database/migration-fixtures/known-good/payroll_ytd.csv.',
                'Toggle a risky feature flag off and confirm the corresponding route returns 404 NOT_FOUND, then restore it with a reason.',
                'Run retention:preview --all to confirm legal-hold visibility without deleting data.',
            ],
            'deferred' => $dataset->deferred_json,
        ];
    }

    /**
     * @return array{name: string, passed: bool, detail: string}
     */
    private function check(string $name, bool $passed, string $detail): array
    {
        return ['name' => $name, 'passed' => $passed, 'detail' => $detail];
    }
}
